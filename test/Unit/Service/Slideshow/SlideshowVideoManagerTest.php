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

use function file_put_contents;
use function getcwd;
use function is_dir;
use function is_file;
use function mkdir;
use function microtime;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function time;
use function touch;
use function uniqid;
use function unlink;
use function usleep;
use function str_repeat;

use const LOCK_EX;
use const PHP_BINARY;

/**
 * @covers \MagicSunday\Memories\Service\Slideshow\SlideshowVideoManager
 */
final class SlideshowVideoManagerTest extends TestCase
{
    public function testEnsureForItemReschedulesStalledJob(): void
    {
        $baseDir = sys_get_temp_dir() . '/memories-slideshow-' . uniqid('', true);
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            self::fail(sprintf('Could not create temporary directory "%s".', $baseDir));
        }

        $imagePath = $baseDir . '/image-one.jpg';
        file_put_contents($imagePath, 'image-stub', LOCK_EX);

        $media = new Media($imagePath, str_repeat('a', 64), 1024);

        $videoPath = $baseDir . '/memory.mp4';
        $jobPath   = $videoPath . '.job.json';
        $lockPath  = $videoPath . '.lock';

        file_put_contents($jobPath, '{}', LOCK_EX);
        file_put_contents($lockPath, '99999', LOCK_EX);

        $staleTime = time() - 3600;
        touch($jobPath, $staleTime);
        touch($lockPath, $staleTime);

        $generator = $this->createMock(SlideshowVideoGeneratorInterface::class);

        $manager = new SlideshowVideoManager(
            $baseDir,
            1.0,
            0.5,
            $generator,
            [],
            null,
            null,
            $this->fixturePath('slideshow-runner.php'),
            PHP_BINARY,
            getcwd(),
        );

        try {
            $status = $manager->ensureForItem('memory', [1], [1 => $media]);

            self::assertSame(SlideshowVideoStatus::STATUS_GENERATING, $status->status());

            $this->waitForFile($videoPath);

            self::assertFileExists($videoPath);
        } finally {
            $this->cleanupDirectory($baseDir);
        }
    }

    private function waitForFile(string $path, int $timeoutMilliseconds = 2000): void
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                return;
            }

            usleep(50_000);
        }

        self::fail(sprintf('File "%s" was not created before timeout.', $path));
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
