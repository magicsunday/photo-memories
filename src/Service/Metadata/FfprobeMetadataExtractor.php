<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use JsonException;
use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Support\MediaFormatGuesser;
use MagicSunday\Memories\Support\IndexLogEntry;
use MagicSunday\Memories\Support\IndexLogHelper;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

use function array_key_exists;
use function array_pad;
use function explode;
use function intdiv;
use function is_array;
use function is_bool;
use function is_file;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Class FfprobeMetadataExtractor.
 */
final readonly class FfprobeMetadataExtractor implements SingleMetadataExtractorInterface
{
    public function __construct(
        private string $ffprobePath = 'ffprobe',
        private float $slowMoFpsThreshold = 100.0,
        private float $processTimeout = 10.0,
        private ?Closure $processRunner = null,
    ) {
    }

    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();

        return $mime !== null && str_starts_with($mime, 'video/');
    }

    public function extract(string $filepath, Media $media): Media
    {
        $media->setIsVideo(true);
        if (!is_file($filepath)) {
            return $media;
        }

        $command = [
            $this->ffprobePath,
            '-v',
            'error',
            '-select_streams',
            'v',
            '-show_entries',
            'stream=codec_name,avg_frame_rate,side_data_list:stream_tags=rotate:format=duration',
            '-of',
            'json',
            $filepath,
        ];

        $out = $this->runCommand($media, $command, 'ffprobe.streams');
        if ($out === null) {
            return $media;
        }

        $payload = json_decode(
            $out,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($payload)) {
            return $media;
        }

        $streamsRaw = $payload['streams'] ?? null;
        $format     = $payload['format'] ?? null;

        $normalisedStreams = $this->normaliseStreams($streamsRaw);
        $media->setVideoStreams($normalisedStreams !== [] ? $normalisedStreams : null);

        $primaryStream = $this->firstStream($streamsRaw);
        if ($primaryStream !== null) {
            $codec = $primaryStream['codec_name'] ?? null;
            if (is_string($codec) && $codec !== '') {
                $media->setVideoCodec($codec);
                if (MediaFormatGuesser::isHevcCodec($codec)) {
                    $media->setIsHevc(true);
                }
            }

            $fps = $this->parseFps(is_string($primaryStream['avg_frame_rate'] ?? null) ? $primaryStream['avg_frame_rate'] : null);
            if ($fps !== null) {
                $media->setVideoFps($fps);
                $media->setIsSlowMo($fps >= $this->slowMoFpsThreshold);
            }

            $rotation = $this->parseStreamRotation($primaryStream);
            if ($rotation !== null) {
                $media->setVideoRotationDeg($rotation);
            }

            $stabilised = $this->parseStreamStabilisation($primaryStream['side_data_list'] ?? null);
            $media->setVideoHasStabilization($stabilised);
        }

        if (is_array($format)) {
            $duration = $this->parseFloat($format['duration'] ?? null);
            if ($duration !== null) {
                $media->setVideoDurationS($duration);
            }
        }

        if ($this->shouldExtractQuickTimeMetadata($media)) {
            $this->applyQuickTimeMetadata($filepath, $media);
        }

        return $media;
    }

    /**
     * @param list<string> $command
     */
    private function runCommand(Media $media, array $command, string $component): ?string
    {
        $runner = $this->processRunner;

        try {
            if ($runner !== null) {
                $result = $runner($command, $this->processTimeout);

                if (is_array($result)) {
                    $exitCode = $result['exitCode'] ?? 0;
                    $stdout   = $result['stdout'] ?? '';
                    if ($exitCode !== 0) {
                        $stderr = trim((string) ($result['stderr'] ?? ''));
                        IndexLogHelper::appendEntry(
                            $media,
                            IndexLogEntry::error(
                                'metadata.ffprobe',
                                'process.failure',
                                sprintf('[%s] ffprobe exited with %d: %s', $component, $exitCode, $stderr),
                                [
                                    'stage' => $component,
                                    'exitCode' => $exitCode,
                                ],
                            ),
                        );

                        return null;
                    }

                    return $this->normaliseOutput($stdout);
                }

                if (is_string($result)) {
                    return $this->normaliseOutput($result);
                }

                return null;
            }

            $process = new Process($command);
            $process->setTimeout($this->processTimeout);
            $process->run();

            if (!$process->isSuccessful()) {
                $stderr   = trim($process->getErrorOutput());
                $exitCode = $process->getExitCode();
                IndexLogHelper::appendEntry(
                    $media,
                    IndexLogEntry::error(
                        'metadata.ffprobe',
                        'process.failure',
                        sprintf('[%s] ffprobe exited with %d: %s', $component, $exitCode ?? -1, $stderr),
                        [
                            'stage' => $component,
                            'exitCode' => $exitCode ?? -1,
                        ],
                    ),
                );

                return null;
            }

            return $this->normaliseOutput($process->getOutput());
        } catch (ProcessTimedOutException $exception) {
            IndexLogHelper::appendEntry(
                $media,
                IndexLogEntry::warning(
                    'metadata.ffprobe',
                    'process.timeout',
                    sprintf('[%s] ffprobe timeout after %.1fs', $component, $exception->getExceededTimeout()),
                    [
                        'stage' => $component,
                        'timeoutSeconds' => $exception->getExceededTimeout(),
                    ],
                ),
            );
        } catch (Throwable $exception) {
            IndexLogHelper::appendEntry(
                $media,
                IndexLogEntry::error(
                    'metadata.ffprobe',
                    'process.error',
                    sprintf('[%s] ffprobe error: %s', $component, $exception->getMessage()),
                    [
                        'stage' => $component,
                    ],
                ),
            );
        }

        return null;
    }

    /**
     * @param array<int, array<int|string, int|float|string|bool|array<int|string, int|float|string|bool|array|null>|null>>|null $streams
     *
     * @return array<int, array<int|string, int|float|string|bool|array<int|string, int|float|string|bool|array|null>|null>>
     */
    private function normaliseStreams(?array $streams): array
    {
        if ($streams === null) {
            return [];
        }

        $result = [];

        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }

            $result[] = $this->normaliseNestedArray($stream);
        }

        return $result;
    }

    /**
     * @param array<int, array<int|string, int|float|string|bool|array<int|string, int|float|string|bool|array|null>|null>>|null $streams
     *
     * @return array<int|string, int|float|string|bool|array<int|string, int|float|string|bool|array|null>|null>|null
     */
    private function firstStream(?array $streams): ?array
    {
        if ($streams === null) {
            return null;
        }

        foreach ($streams as $stream) {
            if (is_array($stream)) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, int|float|string|bool|array<int|string, int|float|string|bool|array|null>|null> $stream
     */
    private function parseStreamRotation(array $stream): ?float
    {
        $tags = $stream['tags'] ?? null;
        if (is_array($tags)) {
            $rotation = $this->parseFloat($tags['rotate'] ?? null);
            if ($rotation !== null) {
                return $rotation;
            }
        }

        $sideData = $stream['side_data_list'] ?? null;
        if (!is_array($sideData)) {
            return null;
        }

        foreach ($sideData as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach (['rotation', 'Rotation', 'angle', 'Angle'] as $rotationKey) {
                if (array_key_exists($rotationKey, $entry)) {
                    $value = $this->parseFloat($entry[$rotationKey]);
                    if ($value !== null) {
                        return $value;
                    }
                }
            }

            $displayMatrix = $entry['displaymatrix'] ?? $entry['display_matrix'] ?? null;
            if (is_array($displayMatrix)) {
                $value = $this->parseFloat($displayMatrix['rotation'] ?? $displayMatrix['Rotation'] ?? null);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array<int|string, int|float|string|bool|array<int|string, int|float|string|bool|array|null>|null>>|null $sideData
     */
    private function parseStreamStabilisation(?array $sideData): ?bool
    {
        if ($sideData === null) {
            return null;
        }

        foreach ($sideData as $entry) {
            $value = $this->extractStabilisationFromEntry(is_array($entry) ? $entry : null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, int|float|string|bool|array<int|string, int|float|string|bool|array|null>|null> $entry
     */
    private function extractStabilisationFromEntry(?array $entry): ?bool
    {
        if ($entry === null) {
            return null;
        }

        $type = $entry['side_data_type'] ?? null;
        if (is_string($type)) {
            $normalisedType = strtolower($type);
            if ($normalisedType === 'camera motion' || $normalisedType === 'camera_motion') {
                return true;
            }
        }

        foreach (['stabilization', 'stabilisation', 'has_stabilization', 'hasStabilization'] as $key) {
            if (array_key_exists($key, $entry)) {
                $value = $this->normaliseBoolean($entry[$key]);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        foreach ($entry as $value) {
            if (!is_array($value)) {
                continue;
            }

            $nested = $this->extractStabilisationFromEntry($value);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, int|float|string|bool|array<int|string, int|float|string|bool|array|null>|null> $value
     *
     * @return array<int|string, int|float|string|bool|array<int|string, int|float|string|bool|array|null>|null>
     */
    private function normaliseNestedArray(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $result[$key] = $this->normaliseNestedArray($item);

                continue;
            }

            if (is_string($item) || is_int($item) || is_float($item) || is_bool($item) || $item === null) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private function normaliseBoolean(string|int|float|bool|null $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        $normalised = strtolower(trim((string) $value));
        if ($normalised === '') {
            return null;
        }

        if (is_numeric($normalised)) {
            return (float) $normalised !== 0.0;
        }

        return match ($normalised) {
            'true', 'yes', 'on', 'enabled', 'stabilized', 'stabilised' => true,
            'false', 'no', 'off', 'disabled' => false,
            default => null,
        };
    }

    private function shouldExtractQuickTimeMetadata(Media $media): bool
    {
        $mime = $media->getMime();

        return $mime !== null && strtolower($mime) === 'video/quicktime';
    }

    private function applyQuickTimeMetadata(string $filepath, Media $media): void
    {
        $currentSource = $media->getTimeSource();
        if ($currentSource !== null && $currentSource !== TimeSource::FILE_MTIME) {
            return;
        }

        $capture = $this->probeQuickTimeCapture($filepath, $media);
        if ($capture === null) {
            return;
        }

        [$takenAt, $tzId] = $capture;

        $media->setTakenAt($takenAt);
        $media->setCapturedLocal($takenAt);
        if ($tzId !== null) {
            $media->setTzId($tzId);
        }

        if ($media->getTimezoneOffsetMin() === null) {
            $media->setTimezoneOffsetMin(intdiv($takenAt->getOffset(), 60));
        }

        $media->setTimeSource(TimeSource::VIDEO_QUICKTIME);
    }

    private function parseFps(?string $v): ?float
    {
        if ($v === null || $v === '0/0' || $v === '') {
            return null;
        }

        if (str_contains($v, '/')) {
            [$a, $b] = array_pad(explode('/', $v, 2), 2, '1');
            $bn      = (float) $b;

            return $bn !== 0.0 ? (float) $a / $bn : null;
        }

        return (float) $v;
    }

    private function parseFloat(string|int|float|null $v): ?float
    {
        if ($v === null) {
            return null;
        }

        if (is_string($v)) {
            $trimmed = trim($v);
            if ($trimmed === '') {
                return null;
            }

            return (float) $trimmed;
        }

        return (float) $v;
    }

    /**
     * @param string $filepath
     *
     * @return array{0: DateTimeImmutable, 1: ?string}|null
     *
     * @throws JsonException
     */
    private function probeQuickTimeCapture(string $filepath, Media $media): ?array
    {
        $command = [
            $this->ffprobePath,
            '-v',
            'error',
            '-show_entries',
            'format_tags=creation_time:format_tags=com.apple.quicktime.creationdate:format_tags=com.apple.quicktime.creatordate',
            '-of',
            'json',
            $filepath,
        ];

        $out = $this->runCommand($media, $command, 'ffprobe.quicktime');
        if ($out === null) {
            return null;
        }

        $data = json_decode(
            $out,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($data)) {
            return null;
        }

        $format = $data['format'] ?? null;
        if (!is_array($format)) {
            return null;
        }

        $tags = $format['tags'] ?? null;
        if (!is_array($tags)) {
            return null;
        }

        $candidates = [
            $tags['com.apple.quicktime.creationdate'] ?? null,
            $tags['com.apple.quicktime.creatordate'] ?? null,
            $tags['creation_time'] ?? null,
        ];

        foreach ($candidates as $value) {
            $instant = $this->parseQuickTimeDate($value);
            if ($instant === null) {
                continue;
            }

            $timezone = $instant->getTimezone();
            $tzName   = $timezone instanceof DateTimeZone ? $timezone->getName() : null;

            return [$instant, $tzName];
        }

        IndexLogHelper::appendEntry(
            $media,
            IndexLogEntry::warning(
                'metadata.ffprobe',
                'quicktime.missing_date',
                '[ffprobe.quicktime] Keine gültige QuickTime-Aufnahmezeit gefunden.',
                [
                    'path' => $filepath,
                ],
            ),
        );

        return null;
    }

    private function normaliseOutput(string $output): ?string
    {
        $trimmed = trim($output);

        return $trimmed === '' ? null : $trimmed;
    }

    private function parseQuickTimeDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $candidates = [$value];
        if (!str_contains($value, 'Z') && !str_contains($value, '+') && !str_contains($value, '-')) {
            $candidates[] = $value . 'Z';
        }

        foreach ($candidates as $candidate) {
            try {
                return new DateTimeImmutable($candidate);
            } catch (Exception) {
                continue;
            }
        }

        return null;
    }
}
