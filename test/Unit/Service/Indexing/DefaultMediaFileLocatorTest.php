<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Indexing;

use MagicSunday\Memories\Service\Indexing\DefaultMediaFileLocator;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_values;
use function count;
use function file_put_contents;
use function is_dir;
use function iterator_to_array;
use function mkdir;
use function rmdir;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class DefaultMediaFileLocatorTest extends TestCase
{
    /**
     * @var list<string> $tempDirs
     */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    #[Test]
    public function locateReturnsOnlyConfiguredExtensions(): void
    {
        $baseDir = $this->createTempDir();

        file_put_contents($baseDir . '/keep.jpg', 'jpg');
        file_put_contents($baseDir . '/clip.mov', 'mov');
        file_put_contents($baseDir . '/ignore.txt', 'txt');

        mkdir($baseDir . '/nested');
        file_put_contents($baseDir . '/nested/also.png', 'png');
        file_put_contents($baseDir . '/nested/skip.gif', 'gif');

        $locator = new DefaultMediaFileLocator(['jpg', 'png'], ['mov']);

        $result = array_values(iterator_to_array($locator->locate($baseDir)));
        sort($result);

        $expected = [
            $baseDir . '/clip.mov',
            $baseDir . '/keep.jpg',
            $baseDir . '/nested/also.png',
        ];
        sort($expected);

        self::assertSame($expected, $result);
    }

    #[Test]
    public function locateRespectsMaxFilesLimit(): void
    {
        $baseDir = $this->createTempDir();

        file_put_contents($baseDir . '/one.jpg', '1');
        file_put_contents($baseDir . '/two.jpg', '2');
        file_put_contents($baseDir . '/three.jpg', '3');

        $locator = new DefaultMediaFileLocator(['jpg'], []);

        $limited = iterator_to_array($locator->locate($baseDir, 2));

        self::assertSame(2, count($limited));
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/memories-indexing-' . uniqid('', true);

        if (!is_dir($dir) && !mkdir($dir) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create temporary directory: ' . $dir);
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof SplFileInfo && $fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());

                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($dir);
    }
}
