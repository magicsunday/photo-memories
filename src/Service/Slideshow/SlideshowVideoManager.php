<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Slideshow;

use MagicSunday\Memories\Entity\Media;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function sprintf;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;

/**
 * Coordinates slideshow generation.
 */
final readonly class SlideshowVideoManager implements SlideshowVideoManagerInterface
{
    private ?string $configuredPhpBinary;

    public function __construct(
        private string $videoDirectory,
        private string $projectDirectory,
        private float $slideDuration,
        ?string $phpBinary,
        private PhpExecutableFinder $phpExecutableFinder,
    ) {
        $phpBinary                 = is_string($phpBinary) ? trim($phpBinary) : null;
        $this->configuredPhpBinary = $phpBinary !== '' ? $phpBinary : null;
    }

    /**
     * @param list<int>        $memberIds
     * @param array<int,Media> $mediaMap
     */
    public function ensureForItem(string $itemId, array $memberIds, array $mediaMap): SlideshowVideoStatus
    {
        $images = $this->collectImages($memberIds, $mediaMap);
        if ($images === []) {
            return SlideshowVideoStatus::unavailable($this->slideDuration);
        }

        $videoPath = $this->buildVideoPath($itemId);
        $lockPath  = $this->buildLockPath($videoPath);
        $errorPath = $this->buildErrorPath($videoPath);
        $jobPath   = $this->buildJobPath($videoPath);

        if (is_file($videoPath)) {
            return SlideshowVideoStatus::ready($this->buildVideoUrl($itemId), $this->slideDuration);
        }

        if (is_file($errorPath)) {
            $message = file_get_contents($errorPath);
            $message = is_string($message) && $message !== '' ? $message : 'Video konnte nicht erzeugt werden.';

            return SlideshowVideoStatus::error($message, $this->slideDuration);
        }

        if (is_file($lockPath)) {
            return SlideshowVideoStatus::generating($this->slideDuration);
        }

        $this->ensureVideoDirectory();

        $handle = @fopen($lockPath,
            'xb'
        );
        if ($handle === false) {
            return SlideshowVideoStatus::generating($this->slideDuration);
        }

        try {
            $job = new SlideshowJob($itemId, $jobPath, $videoPath, $lockPath, $errorPath, $images);
            file_put_contents($jobPath, $job->toJson(), LOCK_EX);
            $this->startGenerator($job);
        } catch (Throwable $throwable) {
            $this->handleGenerationFailure($throwable, $lockPath, $errorPath, $jobPath);

            return SlideshowVideoStatus::error($throwable->getMessage(), $this->slideDuration);
        } finally {
            fclose($handle);
        }

        return SlideshowVideoStatus::generating($this->slideDuration);
    }

    public function resolveVideoPath(string $itemId): ?string
    {
        $path = $this->buildVideoPath($itemId);

        return is_file($path) ? $path : null;
    }

    /**
     * @param list<int>        $memberIds
     * @param array<int,Media> $mediaMap
     *
     * @return list<string>
     */
    private function collectImages(array $memberIds, array $mediaMap): array
    {
        $images = [];
        foreach ($memberIds as $memberId) {
            $media = $mediaMap[$memberId] ?? null;
            if (!$media instanceof Media) {
                continue;
            }

            $path = $media->getPath();
            if ($path !== '' && is_file($path)) {
                $images[] = $path;
            }
        }

        return $images;
    }

    private function buildVideoPath(string $itemId): string
    {
        return $this->videoDirectory . DIRECTORY_SEPARATOR . $itemId . '.mp4';
    }

    private function buildLockPath(string $videoPath): string
    {
        return $videoPath . '.lock';
    }

    private function buildJobPath(string $videoPath): string
    {
        return $videoPath . '.job.json';
    }

    private function buildErrorPath(string $videoPath): string
    {
        return $videoPath . '.error.log';
    }

    private function buildVideoUrl(string $itemId): string
    {
        return sprintf('/api/feed/%s/video', $itemId);
    }

    private function ensureVideoDirectory(): void
    {
        if (is_dir($this->videoDirectory)) {
            return;
        }

        if (!mkdir($this->videoDirectory, 0775, true) && !is_dir($this->videoDirectory)) {
            throw new ProcessRuntimeException(sprintf('Video directory "%s" could not be created.', $this->videoDirectory));
        }
    }

    private function startGenerator(SlideshowJob $job): void
    {
        $console = $this->projectDirectory . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Memories.php';
        if (!is_file($console)) {
            throw new ProcessRuntimeException('Console entry point not found.');
        }

        $process = new Process([
            $this->resolvePhpBinary(),
            $console,
            'slideshow:generate',
            $job->jobFile(),
        ]);

        try {
            $process->disableOutput();
            $process->start();
        } catch (Throwable $throwable) {
            throw new ProcessRuntimeException($throwable->getMessage(), 0, $throwable);
        }
    }

    private function handleGenerationFailure(Throwable $throwable, string $lockPath, string $errorPath, string $jobPath): void
    {
        if (file_exists($lockPath)) {
            unlink($lockPath);
        }

        file_put_contents($errorPath, $throwable->getMessage() ?: 'Unbekannter Fehler bei der Videoerstellung.', LOCK_EX);

        if (file_exists($jobPath)) {
            unlink($jobPath);
        }
    }

    private function resolvePhpBinary(): string
    {
        if ($this->configuredPhpBinary !== null) {
            return $this->configuredPhpBinary;
        }

        $phpBinary = $this->phpExecutableFinder->find(false);
        if (!is_string($phpBinary) || $phpBinary === '') {
            throw new ProcessRuntimeException('PHP CLI executable could not be located.');
        }

        return $phpBinary;
    }
}
