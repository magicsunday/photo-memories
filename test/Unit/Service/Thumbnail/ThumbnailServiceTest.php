<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Thumbnail;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailService;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class ThumbnailServiceTest extends TestCase
{
    #[Test]
    public function throwsExceptionWhenThumbnailDirectoryCannotBeCreated(): void
    {
        $thumbnailDirParent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-parent-' . uniqid('', true);
        $thumbnailDir       = $thumbnailDirParent . DIRECTORY_SEPARATOR . 'child';

        if (!mkdir($thumbnailDirParent) && !is_dir($thumbnailDirParent)) {
            self::fail('Unable to create thumbnail parent directory.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(sprintf('Failed to create thumbnail directory "%s".', $thumbnailDir));

            if (file_put_contents($thumbnailDir, '') === false) {
                self::fail('Unable to create thumbnail directory placeholder file.');
            }

            new ThumbnailService($thumbnailDir, [200]);
        } finally {
            if (is_file($thumbnailDir)) {
                unlink($thumbnailDir);
            }

            if (is_dir($thumbnailDirParent)) {
                rmdir($thumbnailDirParent);
            }
        }
    }

    #[Test]
    public function rotatesImageAccordingToOrientationSix(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD extension is required for this test.');
        }

        $thumbnailDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-' . uniqid('', true);
        if (!@mkdir($thumbnailDir) && !is_dir($thumbnailDir)) {
            self::fail('Unable to create thumbnail directory.');
        }

        $sourcePath = $thumbnailDir . DIRECTORY_SEPARATOR . 'source.jpg';
        $image      = imagecreatetruecolor(400, 200);
        $color      = imagecolorallocate($image, 200, 100, 50);
        imagefilledrectangle($image, 0, 0, 399, 199, $color);
        imagejpeg($image, $sourcePath);
        imagedestroy($image);

        $media = new Media($sourcePath, hash('sha256', 'source'), 1024);
        $media->setOrientation(6);

        $service        = new ThumbnailService($thumbnailDir, [200]);
        $thumbnailPath  = null;

        try {
            $thumbnails = $service->generateAll($sourcePath, $media);

            self::assertArrayHasKey(200, $thumbnails);
            $thumbnailPath = $thumbnails[200];
            self::assertFileExists($thumbnailPath);

            [$width, $height] = getimagesize($thumbnailPath);

            self::assertGreaterThan($width, $height);
        } finally {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }

            if ($thumbnailPath !== null && is_file($thumbnailPath)) {
                @unlink($thumbnailPath);
            }

            if (is_dir($thumbnailDir)) {
                @rmdir($thumbnailDir);
            }
        }
    }
}
