<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Thumbnail;

use Imagick;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailService;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
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
    public function throwsExceptionWhenImagickWriteImageFails(): void
    {
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('Imagick extension is required for this test.');
        }

        $thumbnailDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-' . uniqid('', true);
        if (!@mkdir($thumbnailDir) && !is_dir($thumbnailDir)) {
            self::fail('Unable to create thumbnail directory.');
        }

        $sourceDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-source-' . uniqid('', true);
        if (!@mkdir($sourceDir) && !is_dir($sourceDir)) {
            self::fail('Unable to create thumbnail source directory.');
        }

        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . 'source.jpg';
        if (file_put_contents($sourcePath, 'thumbnail-source') === false) {
            self::fail('Unable to create thumbnail source file.');
        }

        $media = new Media($sourcePath, hash('sha256', 'source'), 1024);

        $expectedOutput = $thumbnailDir . DIRECTORY_SEPARATOR . $media->getChecksum() . '-200.jpg';

        $imagick = $this->createMock(Imagick::class);
        $imagick->expects(self::once())->method('setOption')->with('jpeg:preserve-settings', 'true')->willReturn(true);
        $imagick->expects(self::once())->method('readImage')->with($sourcePath . '[0]')->willReturn(true);
        $imagick->expects(self::once())->method('autoOrientImage')->willReturn(true);
        $imagick->expects(self::once())->method('setImageOrientation')->with(Imagick::ORIENTATION_TOPLEFT)->willReturn(true);
        $imagick->expects(self::once())->method('thumbnailImage')->with(200, 0)->willReturn(true);
        $imagick->expects(self::once())->method('writeImage')->with($expectedOutput)->willReturn(false);
        $imagick->expects(self::exactly(2))->method('clear');
        $imagick->expects(self::exactly(2))->method('destroy');

        $service = new class ($thumbnailDir, [200], $imagick) extends ThumbnailService {
            public function __construct(string $thumbnailDir, array $sizes, private Imagick $imagick)
            {
                parent::__construct($thumbnailDir, $sizes);
            }

            protected function createImagick(): Imagick
            {
                return $this->imagick;
            }

            protected function cloneImagick(Imagick $imagick): Imagick
            {
                return $imagick;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Unable to create thumbnail at path "%s".', $expectedOutput));

        try {
            $service->generateAll($sourcePath, $media);
        } finally {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }

            if (is_dir($sourceDir)) {
                @rmdir($sourceDir);
            }

            if (is_dir($thumbnailDir)) {
                foreach (glob($thumbnailDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    @unlink($file);
                }

                @rmdir($thumbnailDir);
            }
        }
    }

    #[Test]
    public function generatesAllConfiguredThumbnailSizes(): void
    {
        if (!extension_loaded('imagick') && !function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('Imagick or GD extension is required for this test.');
        }

        $thumbnailDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-' . uniqid('', true);
        if (!@mkdir($thumbnailDir) && !is_dir($thumbnailDir)) {
            self::fail('Unable to create thumbnail directory.');
        }

        $sourceDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-source-' . uniqid('', true);
        if (!@mkdir($sourceDir) && !is_dir($sourceDir)) {
            self::fail('Unable to create thumbnail source directory.');
        }

        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . 'source.jpg';

        if (extension_loaded('imagick')) {
            $image = new Imagick();
            $image->newImage(400, 200, 'white');
            $image->setImageFormat('jpeg');
            $image->writeImage($sourcePath);
            $image->clear();
            $image->destroy();
        } else {
            $image = imagecreatetruecolor(400, 200);
            $color = imagecolorallocate($image, 100, 150, 200);
            imagefilledrectangle($image, 0, 0, 399, 199, $color);
            imagejpeg($image, $sourcePath);
            imagedestroy($image);
        }

        $sizes = [120, 240];

        $media  = new Media($sourcePath, hash('sha256', 'source'), 1024);
        $service = new ThumbnailService($thumbnailDir, $sizes);

        $thumbnails = [];
        try {
            $thumbnails = $service->generateAll($sourcePath, $media);

            self::assertCount(2, $thumbnails);
            foreach ($sizes as $size) {
                self::assertArrayHasKey($size, $thumbnails);

                $thumbnailPath = $thumbnails[$size];
                self::assertFileExists($thumbnailPath);

                [$width] = getimagesize($thumbnailPath);
                self::assertSame($size, $width);
            }
        } finally {
            foreach ($thumbnails as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }

            if (is_dir($sourceDir)) {
                @rmdir($sourceDir);
            }

            if (is_dir($thumbnailDir)) {
                @rmdir($thumbnailDir);
            }
        }
    }

    #[Test]
    public function differentMediaDoNotOverwriteThumbnails(): void
    {
        if (!extension_loaded('imagick') && !function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('Imagick or GD extension is required for this test.');
        }

        $thumbnailDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-' . uniqid('', true);
        if (!@mkdir($thumbnailDir) && !is_dir($thumbnailDir)) {
            self::fail('Unable to create thumbnail directory.');
        }

        $sourceDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-source-' . uniqid('', true);
        if (!@mkdir($sourceDir) && !is_dir($sourceDir)) {
            self::fail('Unable to create thumbnail source directory.');
        }

        $firstSource  = $sourceDir . DIRECTORY_SEPARATOR . 'first.jpg';
        $secondSource = $sourceDir . DIRECTORY_SEPARATOR . 'second.jpg';

        if (extension_loaded('imagick')) {
            $image = new Imagick();
            $image->newImage(300, 200, 'white');
            $image->setImageFormat('jpeg');
            $image->writeImage($firstSource);
            $image->clear();
            $image->destroy();

            $image = new Imagick();
            $image->newImage(200, 300, 'white');
            $image->setImageFormat('jpeg');
            $image->writeImage($secondSource);
            $image->clear();
            $image->destroy();
        } else {
            $image = imagecreatetruecolor(300, 200);
            $color = imagecolorallocate($image, 80, 120, 160);
            imagefilledrectangle($image, 0, 0, 299, 199, $color);
            imagejpeg($image, $firstSource);
            imagedestroy($image);

            $image = imagecreatetruecolor(200, 300);
            $color = imagecolorallocate($image, 60, 180, 90);
            imagefilledrectangle($image, 0, 0, 199, 299, $color);
            imagejpeg($image, $secondSource);
            imagedestroy($image);
        }

        $service = new ThumbnailService($thumbnailDir, [200]);

        $firstMedia  = new Media($firstSource, hash('sha256', 'first-source'), 100);
        $secondMedia = new Media($secondSource, hash('sha256', 'second-source'), 200);

        $firstThumbnails  = [];
        $secondThumbnails = [];

        try {
            $firstThumbnails  = $service->generateAll($firstSource, $firstMedia);
            $secondThumbnails = $service->generateAll($secondSource, $secondMedia);

            self::assertArrayHasKey(200, $firstThumbnails);
            self::assertArrayHasKey(200, $secondThumbnails);

            $firstThumbnail  = $firstThumbnails[200];
            $secondThumbnail = $secondThumbnails[200];

            self::assertNotSame($firstThumbnail, $secondThumbnail);
            self::assertFileExists($firstThumbnail);
            self::assertFileExists($secondThumbnail);
        } finally {
            foreach (array_merge($firstThumbnails, $secondThumbnails) as $file) {
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                }
            }

            foreach ([$firstSource, $secondSource] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            if (is_dir($sourceDir)) {
                @rmdir($sourceDir);
            }

            if (is_dir($thumbnailDir)) {
                @rmdir($thumbnailDir);
            }
        }
    }

    #[Test]
    public function throwsExceptionWhenGdCannotReadSourceFile(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD extension is required for this test.');
        }

        $thumbnailDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-' . uniqid('', true);
        if (!@mkdir($thumbnailDir) && !is_dir($thumbnailDir)) {
            self::fail('Unable to create thumbnail directory.');
        }

        $service = new ThumbnailService($thumbnailDir, [200]);
        $missingPath = $thumbnailDir . DIRECTORY_SEPARATOR . 'missing.jpg';

        $method = new ReflectionMethod(ThumbnailService::class, 'generateWithGd');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Unable to read image data from "%s" for thumbnail generation.', $missingPath));

        try {
            $method->invoke($service, $missingPath, 200, null, hash('sha256', 'missing'));
        } finally {
            if (is_dir($thumbnailDir)) {
                @rmdir($thumbnailDir);
            }
        }
    }

    #[Test]
    public function throwsExceptionWhenGdCannotCreateImageFromData(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD extension is required for this test.');
        }

        $thumbnailDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-' . uniqid('', true);
        if (!@mkdir($thumbnailDir) && !is_dir($thumbnailDir)) {
            self::fail('Unable to create thumbnail directory.');
        }

        $sourcePath = $thumbnailDir . DIRECTORY_SEPARATOR . 'invalid.jpg';
        if (file_put_contents($sourcePath, 'not-an-image') === false) {
            self::fail('Unable to create invalid thumbnail source file.');
        }

        $service = new ThumbnailService($thumbnailDir, [200]);

        $method = new ReflectionMethod(ThumbnailService::class, 'generateWithGd');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Unable to create GD image from "%s".', $sourcePath));

        try {
            $method->invoke($service, $sourcePath, 200, null, hash('sha256', 'invalid'));
        } finally {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }

            if (is_dir($thumbnailDir)) {
                @rmdir($thumbnailDir);
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

        $sourceDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-source-' . uniqid('', true);
        if (!@mkdir($sourceDir) && !is_dir($sourceDir)) {
            self::fail('Unable to create thumbnail source directory.');
        }

        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . 'source.jpg';
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

            if (is_dir($sourceDir)) {
                @rmdir($sourceDir);
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

        $sourceDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-source-' . uniqid('', true);
        if (!@mkdir($sourceDir) && !is_dir($sourceDir)) {
            self::fail('Unable to create thumbnail source directory.');
        }

        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . 'source.jpg';
        $image      = imagecreatetruecolor(200, 200);
        $color      = imagecolorallocate($image, 50, 150, 200);
        imagefilledrectangle($image, 0, 0, 199, 199, $color);
        imagejpeg($image, $sourcePath);
        imagedestroy($image);

        $media = new Media($sourcePath, hash('sha256', 'source'), 1024);

        $service = new ThumbnailService($thumbnailDir, [200]);

        $relocatedDir = $thumbnailDir . '-moved';
        if (!@rename($thumbnailDir, $relocatedDir)) {
            self::fail('Unable to relocate thumbnail directory.');
        }

        $method = new ReflectionMethod(ThumbnailService::class, 'generateWithGd');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unable to create thumbnail/');

        try {
        $method->invoke($service, $sourcePath, 200, $media->getOrientation(), $media->getChecksum());
        } finally {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }

            if (is_dir($sourceDir)) {
                @rmdir($sourceDir);
            }

            foreach (glob($thumbnailDir . DIRECTORY_SEPARATOR . '*.jpg') ?: [] as $file) {
                @unlink($file);
            }

            if (is_dir($thumbnailDir)) {
                @rmdir($thumbnailDir);
            }

            foreach (glob($relocatedDir . DIRECTORY_SEPARATOR . '*.jpg') ?: [] as $file) {
                @unlink($file);
            }

            if (is_dir($relocatedDir)) {
                @rmdir($relocatedDir);
            }
        }
    }
}
