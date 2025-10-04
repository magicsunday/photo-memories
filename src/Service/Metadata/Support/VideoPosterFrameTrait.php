<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use MagicSunday\Memories\Entity\Media;
use Symfony\Component\Process\Process;
use Throwable;

use function is_file;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function sprintf;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

/**
 * Shared helper to extract poster frames from videos via ffmpeg/ffprobe.
 */
trait VideoPosterFrameTrait
{
    private function isVideoMedia(Media $media): bool
    {
        if ($media->isVideo()) {
            return true;
        }

        $mime = $media->getMime();

        return is_string($mime) && str_starts_with($mime, 'video/');
    }

    private function createPosterFrame(string $videoPath): ?string
    {
        if (!is_file($videoPath)) {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'mem_poster_');
        if (!is_string($tempFile)) {
            return null;
        }

        $posterPath = $tempFile . '.jpg';
        if (is_file($tempFile)) {
            unlink($tempFile);
        }

        $targetTime = $this->resolvePosterTime($videoPath);

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

        $process = new Process($command);
        $process->setTimeout(20.0);

        try {
            $process->run();
        } catch (Throwable) {
            $this->cleanupPosterFrame($posterPath);

            return null;
        }

        if (!$process->isSuccessful() || !is_file($posterPath)) {
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

    private function resolvePosterTime(string $videoPath): float
    {
        $targetTime = max(0.0, min(2.0, $this->posterFrameSecond));
        if ($targetTime < 1.0) {
            $targetTime = 1.0;
        }

        $duration = $this->probeVideoDuration($videoPath);
        if ($duration !== null && $duration > 0.0 && $targetTime > $duration) {
            $targetTime = max(0.0, $duration - 0.1);
        }

        return $targetTime;
    }

    private function probeVideoDuration(string $videoPath): ?float
    {
        $process = new Process([
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
        ]);
        $process->setTimeout(10.0);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();
        if ($output === '') {
            $output = $process->getErrorOutput();
        }

        $output = trim($output);
        if ($output === '') {
            return null;
        }

        if (!is_numeric($output)) {
            return null;
        }

        $duration = (float) $output;

        return $duration > 0.0 ? $duration : null;
    }
}
