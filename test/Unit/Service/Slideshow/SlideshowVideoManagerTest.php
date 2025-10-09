<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Slideshow;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoManager;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoGeneratorInterface;
use MagicSunday\Memories\Service\Slideshow\SlideshowVideoStatus;
use MagicSunday\Memories\Test\TestCase;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function str_repeat;

use const LOCK_EX;

/**
 * @covers \MagicSunday\Memories\Service\Slideshow\SlideshowVideoManager
 */
final class SlideshowVideoManagerTest extends TestCase
{
    public function testEnsureForItemGeneratesVideoInline(): void
    {
        $baseDir = sys_get_temp_dir() . '/memories-slideshow-' . uniqid('', true);
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            self::fail(sprintf('Could not create temporary directory "%s".', $baseDir));
        }

        $imagePath = $baseDir . '/image-one.jpg';
        file_put_contents($imagePath, 'image-stub', LOCK_EX);

        $media = new Media($imagePath, str_repeat('a', 64), 1024);

        $generator = new class implements SlideshowVideoGeneratorInterface {
            public function generate(\MagicSunday\Memories\Service\Slideshow\SlideshowJob $job): void
            {
                file_put_contents($job->outputPath(), 'video-stub', LOCK_EX);
            }
        };

        $manager = new SlideshowVideoManager(
            $baseDir,
            1.0,
            0.5,
            $generator,
            [],
            null,
            null,
        );

        try {
            $status    = $manager->ensureForItem('memory', [1], [1 => $media]);
            $videoPath = $baseDir . '/memory.mp4';
            $lockPath  = $videoPath . '.lock';
            $errorPath = $videoPath . '.error.log';

            self::assertSame(SlideshowVideoStatus::STATUS_READY, $status->status());
            self::assertFileExists($videoPath);
            self::assertFileDoesNotExist($lockPath);
            self::assertFileDoesNotExist($errorPath);
        } finally {
            $this->cleanupDirectory($baseDir);
        }
    }

    public function testEnsureForItemReturnsErrorWhenGenerationFails(): void
    {
        $baseDir = sys_get_temp_dir() . '/memories-slideshow-' . uniqid('', true);
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            self::fail(sprintf('Could not create temporary directory "%s".', $baseDir));
        }

        $imagePath = $baseDir . '/image-error.jpg';
        file_put_contents($imagePath, 'image-stub', LOCK_EX);

        $media = new Media($imagePath, str_repeat('c', 64), 2048);

        $generator = new class implements SlideshowVideoGeneratorInterface {
            public function generate(\MagicSunday\Memories\Service\Slideshow\SlideshowJob $job): void
            {
                throw new \RuntimeException('ffmpeg failed');
            }
        };

        $manager = new SlideshowVideoManager(
            $baseDir,
            1.0,
            0.5,
            $generator,
            [],
            null,
            null,
        );

        try {
            $status    = $manager->ensureForItem('memory-error', [3], [3 => $media]);
            $videoPath = $baseDir . '/memory-error.mp4';
            $lockPath  = $videoPath . '.lock';
            $errorPath = $videoPath . '.error.log';

            self::assertSame(SlideshowVideoStatus::STATUS_ERROR, $status->status());
            self::assertFileDoesNotExist($lockPath);
            self::assertFileExists($errorPath);

            $message = file_get_contents($errorPath);
            self::assertIsString($message);
            self::assertStringContainsString('ffmpeg failed', $message);
        } finally {
            $this->cleanupDirectory($baseDir);
        }
    }

    private function cleanupDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->cleanupDirectory($full);
                continue;
            }

            if (is_file($full)) {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
