<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MagicSunday\Memories\Entity\Enum\TimeSource;
use MagicSunday\Memories\Entity\Media;

use function array_map;
use function array_pad;
use function count;
use function escapeshellarg;
use function escapeshellcmd;
use function explode;
use function intdiv;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function shell_exec;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;

final readonly class FfprobeMetadataExtractor implements SingleMetadataExtractorInterface
{
    public function __construct(
        private string $ffprobePath = 'ffprobe',
        private float $slowMoFpsThreshold = 100.0,
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

        $cmd = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=codec_name,avg_frame_rate -show_entries format=duration -of default=nw=1:nk=1 %s',
            escapeshellcmd($this->ffprobePath),
            escapeshellarg($filepath)
        );
        $out = @shell_exec($cmd);
        if (!is_string($out) || $out === '') {
            return $media;
        }

        $lines = array_map('trim', explode("\n", $out));
        if (count($lines) >= 3) {
            $codec = $lines[0] !== '' ? $lines[0] : null;
            $fps   = $this->parseFps($lines[1] ?? null);
            $dur   = $this->parseFloat($lines[2] ?? null);

            if ($codec !== null) {
                $media->setVideoCodec($codec);
            }

            if ($fps !== null) {
                $media->setVideoFps($fps);
            }

            if ($dur !== null) {
                $media->setVideoDurationS($dur);
            }

            if ($fps !== null) {
                $media->setIsSlowMo($fps >= $this->slowMoFpsThreshold);
            }
        }

        if ($this->shouldExtractQuickTimeMetadata($media)) {
            $this->applyQuickTimeMetadata($filepath, $media);
        }

        return $media;
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

        $capture = $this->probeQuickTimeCapture($filepath);
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

    private function parseFloat(?string $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (float) $v;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: ?string}|null
     */
    private function probeQuickTimeCapture(string $filepath): ?array
    {
        $cmd = sprintf(
            '%s -v error -show_entries format_tags=creation_time:format_tags=com.apple.quicktime.creationdate -of json %s',
            escapeshellcmd($this->ffprobePath),
            escapeshellarg($filepath),
        );

        $out = @shell_exec($cmd);
        if (!is_string($out) || $out === '') {
            return null;
        }

        $data = json_decode($out, true);
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
            $tags['creation_time'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            try {
                $instant = new DateTimeImmutable($value);
            } catch (Exception) {
                continue;
            }

            $timezone = $instant->getTimezone();
            $tzName   = $timezone instanceof DateTimeZone ? $timezone->getName() : null;

            return [$instant, $tzName];
        }

        return null;
    }
}
