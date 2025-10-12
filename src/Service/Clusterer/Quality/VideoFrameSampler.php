<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Quality;

use MagicSunday\Memories\Entity\Media;
use Symfony\Component\Process\Process;
use Throwable;

use function is_array;
use function is_file;
use function is_numeric;
use function is_readable;
use function is_string;
use function max;
use function min;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

/**
 * Creates temporary poster frames for video quality evaluation using ffprobe metadata.
 */
final class VideoFrameSampler implements VideoFrameSamplerInterface
{
    private string $ffmpegBinary;

    private string $ffprobeBinary;

    private float $posterFrameSecond;

    private float $processTimeout;

    /**
     * @var callable|null
     */
    private $processRunner;

    public function __construct(
        string $ffmpegBinary = 'ffmpeg',
        string $ffprobeBinary = 'ffprobe',
        float $posterFrameSecond = 1.0,
        float $processTimeout = 12.0,
        ?callable $processRunner = null,
    ) {
        $normalizedFfmpeg = trim($ffmpegBinary);
        if ($normalizedFfmpeg === '') {
            $normalizedFfmpeg = 'ffmpeg';
        }

        $normalizedFfprobe = trim($ffprobeBinary);
        if ($normalizedFfprobe === '') {
            $normalizedFfprobe = 'ffprobe';
        }

        if ($posterFrameSecond < 0.0) {
            $posterFrameSecond = 0.0;
        }

        if ($processTimeout < 1.0) {
            $processTimeout = 1.0;
        }

        $this->ffmpegBinary      = $normalizedFfmpeg;
        $this->ffprobeBinary     = $normalizedFfprobe;
        $this->posterFrameSecond = $posterFrameSecond;
        $this->processTimeout    = $processTimeout;
        $this->processRunner     = $processRunner;
    }

    public function sampleLumaMatrix(Media $media, callable $loader): ?array
    {
        if (!$media->isVideo()) {
            return null;
        }

        $videoPath = $media->getPath();
        if ($videoPath === '' || !is_file($videoPath) || !is_readable($videoPath)) {
            return null;
        }

        $posterPath = $this->createPosterFrame($media, $videoPath);
        if (!is_string($posterPath) || $posterPath === '') {
            return null;
        }

        try {
            $matrix = $loader($posterPath);
            if (!is_array($matrix)) {
                return null;
            }

            return $matrix;
        } finally {
            $this->cleanupPosterFrame($posterPath);
        }
    }

    private function createPosterFrame(Media $media, string $videoPath): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'mem_vframe_');
        if (!is_string($tempFile)) {
            return null;
        }

        $posterPath = $tempFile . '.jpg';
        if (is_file($tempFile)) {
            unlink($tempFile);
        }

        $targetTime = $this->resolvePosterTime($media, $videoPath);

        $command = [$this->ffmpegBinary, '-y', '-loglevel', 'error'];

        if ($targetTime > 0.0) {
            $command[] = '-ss';
            $command[] = sprintf('%.3f', $targetTime);
        }

        $command[] = '-i';
        $command[] = $videoPath;
        $command[] = '-frames:v';
        $command[] = '1';
        $command[] = $posterPath;

        $result = $this->runProcess($command, $this->processTimeout);
        if (($result['exitCode'] ?? -1) !== 0 || !is_file($posterPath)) {
            $this->cleanupPosterFrame($posterPath);

            return null;
        }

        return $posterPath;
    }

    private function cleanupPosterFrame(?string $posterPath): void
    {
        if (is_string($posterPath) && $posterPath !== '' && is_file($posterPath)) {
            unlink($posterPath);
        }
    }

    private function resolvePosterTime(Media $media, string $videoPath): float
    {
        $duration = $media->getVideoDurationS();
        if ($duration === null) {
            $duration = $this->probeVideoDuration($videoPath);
        }

        if ($duration !== null && $duration > 0.0) {
            $candidate = $duration * 0.25;
            if ($candidate < 0.3) {
                $candidate = min(0.3, $duration);
            }

            if ($candidate > $duration - 0.1) {
                $candidate = max(0.0, $duration - 0.1);
            }

            return max(0.0, $candidate);
        }

        $target = max(0.0, min(2.0, $this->posterFrameSecond));
        if ($target < 0.5) {
            $target = 0.5;
        }

        return $target;
    }

    private function probeVideoDuration(string $videoPath): ?float
    {
        $command = [
            $this->ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $videoPath,
        ];

        $result = $this->runProcess($command, max(1.0, $this->processTimeout / 2.0));
        if (($result['exitCode'] ?? -1) !== 0) {
            return null;
        }

        $output = trim((string) ($result['stdout'] ?? ''));
        if ($output === '') {
            $output = trim((string) ($result['stderr'] ?? ''));
        }

        if ($output === '') {
            return null;
        }

        if (!is_numeric($output)) {
            return null;
        }

        $duration = (float) $output;
        if ($duration <= 0.0) {
            return null;
        }

        return $duration;
    }

    /**
     * @param list<string> $command
     *
     * @return array{exitCode: int|null, stdout: string|null, stderr: string|null}
     */
    private function runProcess(array $command, float $timeout): array
    {
        $runner = $this->processRunner;
        if ($runner !== null) {
            $result = $runner($command, $timeout);
            if (is_array($result)) {
                return [
                    'exitCode' => $result['exitCode'] ?? null,
                    'stdout' => $result['stdout'] ?? null,
                    'stderr' => $result['stderr'] ?? null,
                ];
            }

            return [
                'exitCode' => null,
                'stdout' => null,
                'stderr' => null,
            ];
        }

        $process = new Process($command);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (Throwable) {
            return [
                'exitCode' => null,
                'stdout' => null,
                'stderr' => null,
            ];
        }

        return [
            'exitCode' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
