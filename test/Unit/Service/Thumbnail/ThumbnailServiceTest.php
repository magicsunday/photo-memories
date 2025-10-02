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

    #[Test]
    public function throwsExceptionWhenTargetDirectoryIsNotWritable(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD extension is required for this test.');
        }

        $thumbnailDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-' . uniqid('', true);
        if (!@mkdir($thumbnailDir) && !is_dir($thumbnailDir)) {
            self::fail('Unable to create thumbnail directory.');
        }

        $sourcePath = $thumbnailDir . DIRECTORY_SEPARATOR . 'source.jpg';
        $image      = imagecreatetruecolor(200, 200);
        $color      = imagecolorallocate($image, 50, 150, 200);
        imagefilledrectangle($image, 0, 0, 199, 199, $color);
        imagejpeg($image, $sourcePath);
        imagedestroy($image);

        $media = new Media($sourcePath, hash('sha256', 'source'), 1024);

        $service = new ThumbnailService($thumbnailDir, [200]);

        chmod($thumbnailDir, 0555);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unable to create thumbnail/');

        try {
            $service->generateAll($sourcePath, $media);
        } finally {
            chmod($thumbnailDir, 0755);

            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }

            foreach (glob($thumbnailDir . DIRECTORY_SEPARATOR . '*.jpg') ?: [] as $file) {
                @unlink($file);
            }

            if (is_dir($thumbnailDir)) {
                @rmdir($thumbnailDir);
            }
        }
    }
}
