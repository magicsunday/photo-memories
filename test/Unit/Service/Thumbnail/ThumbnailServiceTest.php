<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Thumbnail {
    use MagicSunday\Memories\Test\Unit\Service\Thumbnail\ThumbnailServiceTest;

    if (!function_exists(__NAMESPACE__ . '\\imagecopyresampled')) {
        function imagecopyresampled($dstImage, $srcImage, int $dstX, int $dstY, int $srcX, int $srcY, int $dstWidth, int $dstHeight, int $srcWidth, int $srcHeight): bool
        {
            if (\MagicSunday\Memories\Test\Unit\Service\Thumbnail\ThumbnailServiceTest::isGdResampleFailureForced()) {
                return false;
            }

            return \imagecopyresampled($dstImage, $srcImage, $dstX, $dstY, $srcX, $srcY, $dstWidth, $dstHeight, $srcWidth, $srcHeight);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\imagecreatefromjpeg')) {
        function imagecreatefromjpeg(string $filename)
        {
            ThumbnailServiceTest::recordGdLoader('imagecreatefromjpeg');

            if (!function_exists('\\imagecreatefromjpeg')) {
                return false;
            }

            return \imagecreatefromjpeg($filename);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\imagecreatefrompng')) {
        function imagecreatefrompng(string $filename)
        {
            ThumbnailServiceTest::recordGdLoader('imagecreatefrompng');

            if (!function_exists('\\imagecreatefrompng')) {
                return false;
            }

            return \imagecreatefrompng($filename);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\imagecreatefromgif')) {
        function imagecreatefromgif(string $filename)
        {
            ThumbnailServiceTest::recordGdLoader('imagecreatefromgif');

            if (!function_exists('\\imagecreatefromgif')) {
                return false;
            }

            return \imagecreatefromgif($filename);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\imagecreatefromwebp')) {
        function imagecreatefromwebp(string $filename)
        {
            ThumbnailServiceTest::recordGdLoader('imagecreatefromwebp');

            if (!function_exists('\\imagecreatefromwebp')) {
                return false;
            }

            return \imagecreatefromwebp($filename);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\imagecreatefromstring')) {
        function imagecreatefromstring(string $string)
        {
            ThumbnailServiceTest::recordGdLoader('imagecreatefromstring');

            return \imagecreatefromstring($string);
        }
    }
}

namespace MagicSunday\Memories\Test\Unit\Service\Thumbnail {

use Imagick;
use ImagickDraw;
use ImagickPixel;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailService;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;

final class ThumbnailServiceTest extends TestCase
{
    private static bool $forceGdResampleFailure = false;
    private static ?string $lastGdLoader = null;

    public static function isGdResampleFailureForced(): bool
    {
        return self::$forceGdResampleFailure;
    }

    public static function recordGdLoader(string $loader): void
    {
        self::$lastGdLoader = $loader;
    }

    public static function getLastGdLoader(): ?string
    {
        return self::$lastGdLoader;
    }

    public static function resetLastGdLoader(): void
    {
        self::$lastGdLoader = null;
    }

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
        $imagick->expects(self::once())->method('setImageFormat')->with('jpeg')->willReturn(true);
        $imagick->expects(self::once())->method('setImageCompression')->with(Imagick::COMPRESSION_JPEG)->willReturn(true);
        $imagick->expects(self::once())->method('setImageCompressionQuality')->with(85)->willReturn(true);
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
    public function throwsExceptionWhenGdResampleFails(): void
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
        $color      = imagecolorallocate($image, 50, 60, 70);
        imagefilledrectangle($image, 0, 0, 399, 199, $color);
        imagejpeg($image, $sourcePath);
        imagedestroy($image);

        $media    = new Media($sourcePath, hash('sha256', 'source'), 1024);
        $service  = new ThumbnailService($thumbnailDir, [200]);
        $checksum = $media->getChecksum();

        $method = new ReflectionMethod(ThumbnailService::class, 'generateThumbnailsWithGd');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to resample image for thumbnail.');

        self::$forceGdResampleFailure = true;

        try {
            $method->invoke($service, $sourcePath, null, [200], $checksum, 'image/jpeg');
        } finally {
            self::$forceGdResampleFailure = false;

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
    public function gdLoaderUsesMimeSpecificFunctionWhenAvailable(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            self::markTestSkipped('GD extension with JPEG support is required for this test.');
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
        $image      = imagecreatetruecolor(80, 60);
        $color      = imagecolorallocate($image, 10, 20, 30);
        imagefilledrectangle($image, 0, 0, 79, 59, $color);
        imagejpeg($image, $sourcePath);
        imagedestroy($image);

        $media   = new Media($sourcePath, hash('sha256', 'gd-loader-source'), 2048);
        $service = new ThumbnailService($thumbnailDir, [60]);

        $method = new ReflectionMethod(ThumbnailService::class, 'generateThumbnailsWithGd');
        $method->setAccessible(true);

        self::resetLastGdLoader();

        $thumbnails = [];

        try {
            /** @var array<int, string> $thumbnails */
            $thumbnails = $method->invoke($service, $sourcePath, null, [60], $media->getChecksum(), 'image/jpeg');

            self::assertArrayHasKey(60, $thumbnails);

            $thumbnailPath = $thumbnails[60];
            self::assertFileExists($thumbnailPath);

            self::assertSame('imagecreatefromjpeg', self::getLastGdLoader());
        } finally {
            self::resetLastGdLoader();

            foreach ($thumbnails as $file) {
                if (is_string($file) && is_file($file)) {
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
    public function gdLoaderFallsBackToStringWhenMimeUnknown(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            self::markTestSkipped('GD extension with JPEG support is required for this test.');
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
        $image      = imagecreatetruecolor(80, 60);
        $color      = imagecolorallocate($image, 90, 100, 110);
        imagefilledrectangle($image, 0, 0, 79, 59, $color);
        imagejpeg($image, $sourcePath);
        imagedestroy($image);

        $media   = new Media($sourcePath, hash('sha256', 'gd-loader-fallback'), 4096);
        $service = new ThumbnailService($thumbnailDir, [60]);

        $method = new ReflectionMethod(ThumbnailService::class, 'generateThumbnailsWithGd');
        $method->setAccessible(true);

        self::resetLastGdLoader();

        $thumbnails = [];

        try {
            /** @var array<int, string> $thumbnails */
            $thumbnails = $method->invoke($service, $sourcePath, null, [60], $media->getChecksum(), 'application/octet-stream');

            self::assertArrayHasKey(60, $thumbnails);

            $thumbnailPath = $thumbnails[60];
            self::assertFileExists($thumbnailPath);

            self::assertSame('imagecreatefromstring', self::getLastGdLoader());
        } finally {
            self::resetLastGdLoader();

            foreach ($thumbnails as $file) {
                if (is_string($file) && is_file($file)) {
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
    public function imagickRemovesAlphaChannelWhenGeneratingThumbnails(): void
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

        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . 'transparent.png';

        $image = new Imagick();
        $image->newImage(60, 60, new ImagickPixel('transparent'), 'png');

        $draw = new ImagickDraw();
        $draw->setFillColor('red');
        $draw->circle(30, 30, 30, 15);
        $image->drawImage($draw);
        $image->writeImage($sourcePath);
        $image->clear();
        $image->destroy();

        $media    = new Media($sourcePath, hash('sha256', 'imagick-alpha-source'), 512);
        $service  = new ThumbnailService($thumbnailDir, [40]);
        $thumbnails = [];

        try {
            $thumbnails = $service->generateAll($sourcePath, $media);

            self::assertArrayHasKey(40, $thumbnails);

            $thumbnailPath = $thumbnails[40];
            self::assertFileExists($thumbnailPath);

            $thumbnail = new Imagick($thumbnailPath);

            $cornerColor = $thumbnail->getImagePixelColor(0, 0)->getColor();
            self::assertGreaterThanOrEqual(250, $cornerColor['r']);
            self::assertGreaterThanOrEqual(250, $cornerColor['g']);
            self::assertGreaterThanOrEqual(250, $cornerColor['b']);

            $centerColor = $thumbnail->getImagePixelColor(20, 20)->getColor();
            self::assertGreaterThanOrEqual(180, $centerColor['r']);
            self::assertLessThanOrEqual(90, $centerColor['g']);
            self::assertLessThanOrEqual(90, $centerColor['b']);

            $thumbnail->clear();
            $thumbnail->destroy();
        } finally {
            foreach ($thumbnails as $file) {
                if (is_string($file) && is_file($file)) {
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

    #[Test]
    public function gdFillsTransparentBackgroundBeforeResampling(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            self::markTestSkipped('GD extension with WebP support is required for this test.');
        }

        $thumbnailDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-' . uniqid('', true);
        if (!@mkdir($thumbnailDir) && !is_dir($thumbnailDir)) {
            self::fail('Unable to create thumbnail directory.');
        }

        $sourceDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-thumb-source-' . uniqid('', true);
        if (!@mkdir($sourceDir) && !is_dir($sourceDir)) {
            self::fail('Unable to create thumbnail source directory.');
        }

        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . 'transparent.webp';

        $image = imagecreatetruecolor(60, 60);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        $opaque = imagecolorallocatealpha($image, 0, 0, 255, 0);
        imagefilledellipse($image, 30, 30, 20, 20, $opaque);

        $webpResult = imagewebp($image, $sourcePath, 100);
        imagedestroy($image);

        if ($webpResult === false) {
            self::fail('Unable to create WebP source image.');
        }

        $media   = new Media($sourcePath, hash('sha256', 'gd-alpha-source'), 256);
        $service = new ThumbnailService($thumbnailDir, [50]);

        $method = new ReflectionMethod(ThumbnailService::class, 'generateThumbnailsWithGd');
        $method->setAccessible(true);

        $thumbnails = [];

        try {
            /** @var array<int, string> $thumbnails */
            $thumbnails = $method->invoke($service, $sourcePath, null, [50], $media->getChecksum(), 'image/webp');

            self::assertArrayHasKey(50, $thumbnails);

            $thumbnailPath = $thumbnails[50];
            self::assertFileExists($thumbnailPath);

            if (extension_loaded('imagick')) {
                $thumbnail = new Imagick($thumbnailPath);

                $cornerColor = $thumbnail->getImagePixelColor(0, 0)->getColor();
                self::assertGreaterThanOrEqual(250, $cornerColor['r']);
                self::assertGreaterThanOrEqual(250, $cornerColor['g']);
                self::assertGreaterThanOrEqual(250, $cornerColor['b']);

                $centerColor = $thumbnail->getImagePixelColor(25, 25)->getColor();
                self::assertLessThanOrEqual(80, $centerColor['b']);

                $thumbnail->clear();
                $thumbnail->destroy();
            } else {
                $thumbnailData = file_get_contents($thumbnailPath);

                if ($thumbnailData === false) {
                    self::fail('Unable to read generated thumbnail for inspection.');
                }

                $thumbnailImage = imagecreatefromstring($thumbnailData);

                if (!$thumbnailImage instanceof GdImage) {
                    self::markTestSkipped('JPEG decoding is unavailable for thumbnail inspection.');
                }

                $cornerColorIndex = imagecolorat($thumbnailImage, 0, 0);
                $cornerColor      = imagecolorsforindex($thumbnailImage, $cornerColorIndex);
                self::assertGreaterThanOrEqual(250, $cornerColor['red']);
                self::assertGreaterThanOrEqual(250, $cornerColor['green']);
                self::assertGreaterThanOrEqual(250, $cornerColor['blue']);

                $centerColorIndex = imagecolorat($thumbnailImage, 25, 25);
                $centerColor      = imagecolorsforindex($thumbnailImage, $centerColorIndex);
                self::assertLessThanOrEqual(80, $centerColor['blue']);

                imagedestroy($thumbnailImage);
            }
        } finally {
            foreach ($thumbnails as $file) {
                if (is_string($file) && is_file($file)) {
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
}
}
