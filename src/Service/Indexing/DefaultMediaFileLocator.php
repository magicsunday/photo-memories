<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function in_array;
use function pathinfo;
use function strtolower;

use const PATHINFO_EXTENSION;

/**
 * Default implementation locating image and video files by extension.
 */
final readonly class DefaultMediaFileLocator implements MediaFileLocatorInterface
{
    /**
     * @var list<string>
     */
    private readonly array $imageExtensions;

    /**
     * @var list<string>
     */
    private readonly array $videoExtensions;

    private const array DEFAULT_IMAGE_EXT = [
        'jpg', 'jpeg', 'jpe', 'jxl', 'avif', 'heic', 'heif', 'png', 'webp', 'gif', 'bmp', 'tiff', 'tif',
        'cr2', 'cr3', 'nef', 'arw', 'rw2', 'raf', 'dng',
    ];

    private const array DEFAULT_VIDEO_EXT = [
        'mp4', 'm4v', 'mov', '3gp', '3g2', 'avi', 'mkv', 'webm',
    ];

    /**
     * @param list<string>|null $imageExtensions
     * @param list<string>|null $videoExtensions
     */
    public function __construct(
        ?array $imageExtensions = null,
        ?array $videoExtensions = null,
    ) {
        $this->imageExtensions = $imageExtensions ?? self::DEFAULT_IMAGE_EXT;
        $this->videoExtensions = $videoExtensions ?? self::DEFAULT_VIDEO_EXT;
    }

    public function locate(string $baseDir, ?int $maxFiles = null): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
        );

        $count = 0;

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() === false) {
                continue;
            }

            $path = $fileInfo->getPathname();
            if ($this->isSupported($path) === false) {
                continue;
            }

            yield $path;

            if ($maxFiles !== null) {
                ++$count;

                if ($count >= $maxFiles) {
                    break;
                }
            }
        }
    }

    private function isSupported(string $path): bool
    {
        if ($this->isImage($path)) {
            return true;
        }

        return $this->isVideo($path);
    }

    private function isImage(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->imageExtensions, true);
    }

    private function isVideo(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->videoExtensions, true);
    }
}
