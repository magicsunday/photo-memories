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

use GdImage;
use Imagick;
use ImagickDraw;
use ImagickException;
use ImagickPixel;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailService;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function fallsBackToGdWhenImagickWriteImageFails(): void
    {
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('Imagick extension is required for this test.');
        }

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
        $image      = imagecreatetruecolor(160, 120);
        $color      = imagecolorallocate($image, 120, 130, 140);
        imagefilledrectangle($image, 0, 0, 159, 119, $color);
        imagejpeg($image, $sourcePath);
        imagedestroy($image);

        $media = new Media($sourcePath, hash('sha256', 'source'), 1024);
        $media->setMime('image/jpeg');

        $expectedOutput = $thumbnailDir . DIRECTORY_SEPARATOR . $media->getChecksum() . '-200.jpg';

        $imagick = $this->createMock(Imagick::class);
        $imagick->expects(self::once())->method('setOption')->with('jpeg:preserve-settings', 'true')->willReturn(true);
        $imagick->expects(self::once())->method('readImage')->with($sourcePath . '[0]')->willReturn(true);

        if (method_exists(Imagick::class, 'autoOrientImage')) {
            $imagick->expects(self::once())->method('autoOrientImage')->willReturn(true);
        } else {
            $imagick->expects(self::once())->method('getImageOrientation')->willReturn(Imagick::ORIENTATION_TOPLEFT);
        }

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

        self::resetLastGdLoader();

        $thumbnails = [];

        try {
            $thumbnails = $service->generateAll($sourcePath, $media);

            self::assertArrayHasKey(200, $thumbnails);
            self::assertFileExists($expectedOutput);
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
                foreach (glob($thumbnailDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    @unlink($file);
                }

                @rmdir($thumbnailDir);
            }
        }
    }

    #[Test]
    public function fallsBackToGdWhenImagickThrowsException(): void
    {
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('Imagick extension is required for this test.');
        }

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
        $image      = imagecreatetruecolor(120, 80);
        $color      = imagecolorallocate($image, 30, 40, 50);
        imagefilledrectangle($image, 0, 0, 119, 79, $color);
        imagejpeg($image, $sourcePath);
        imagedestroy($image);

        $media   = new Media($sourcePath, hash('sha256', 'imagick-fallback'), 1024);
        $media->setMime('image/jpeg');
        $sizes   = [100];
        $service = new class ($thumbnailDir, $sizes) extends ThumbnailService {
            public function __construct(string $thumbnailDir, array $sizes)
            {
                parent::__construct($thumbnailDir, $sizes);
            }

            /**
             * @throws ImagickException
             */
            protected function createImagick(): Imagick
            {
                throw new ImagickException('Simulated Imagick failure.');
            }
        };

        self::resetLastGdLoader();

        $thumbnails = [];

        try {
            $thumbnails = $service->generateAll($sourcePath, $media);

            self::assertArrayHasKey(100, $thumbnails);

            $thumbnailPath = $thumbnails[100];
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
                foreach (glob($thumbnailDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    @unlink($file);
                }

                @rmdir($thumbnailDir);
            }
        }
    }

    #[Test]
    public function generatedThumbnailsNeverExceedSourceWidth(): void
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

        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . 'no-upscale.jpg';
        $image      = imagecreatetruecolor(150, 100);
        $color      = imagecolorallocate($image, 120, 130, 140);
        imagefilledrectangle($image, 0, 0, 149, 99, $color);
        imagejpeg($image, $sourcePath);
        imagedestroy($image);

        $media = new Media($sourcePath, hash('sha256', 'no-upscale'), 1024);
        $media->setMime('image/jpeg');

        $service = new ThumbnailService($thumbnailDir, [64, 512]);

        $thumbnails = [];

        try {
            $thumbnails = $service->generateAll($sourcePath, $media);

            self::assertNotSame([], $thumbnails);

            foreach ($thumbnails as $width => $path) {
                self::assertLessThanOrEqual(150, $width);
                self::assertFileExists($path);

                $imageData = getimagesize($path);
                self::assertIsArray($imageData);
                self::assertLessThanOrEqual(150, $imageData[0]);
            }
        } finally {
            foreach ($thumbnails as $path) {
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
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
                self::assertLessThanOrEqual(80, $centerColor['r']);

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
                self::assertLessThanOrEqual(80, $centerColor['red']);

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

    #[Test]
    #[DataProvider('exifOrientationDataProvider')]
    public function appliesExifOrientationForAllSupportedValues(int $orientation, array $operations): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD extension is required for this test.');
        }

        $service      = $this->createOrientationTestService();
        $baseLayout   = $this->createBaseOrientationLayout();
        $storedLayout = $this->applyLayoutOperations($baseLayout, $operations);

        $this->assertOrientationWithGd($service, $orientation, $storedLayout, $baseLayout);

        if (extension_loaded('imagick')) {
            $this->assertOrientationWithImagick($service, $orientation, $storedLayout, $baseLayout);
        }
    }

    public static function exifOrientationDataProvider(): iterable
    {
        yield 'mirror-horizontal' => [2, ['flip-horizontal']];
        yield 'upside-down' => [3, ['rotate-180']];
        yield 'mirror-vertical' => [4, ['flip-vertical']];
        yield 'mirror-horizontal-rotate-ccw' => [5, ['rotate--90', 'flip-horizontal']];
        yield 'rotate-clockwise' => [6, ['rotate-90']];
        yield 'mirror-horizontal-rotate-cw' => [7, ['rotate-90', 'flip-horizontal']];
        yield 'rotate-counter-clockwise' => [8, ['rotate--90']];
    }

    private function assertOrientationWithGd(OrientationThumbnailServiceStub $service, int $orientation, array $storedLayout, array $expectedLayout): void
    {
        $image    = $this->createGdImageFromLayout($storedLayout);
        $oriented = $image;

        try {
            $oriented = $service->orientGdImage($image, $orientation);
            $actual   = $this->extractLayoutFromGdImage($oriented);

            $this->assertLayoutEquals($expectedLayout, $actual, 'GD');
        } finally {
            if ($oriented instanceof GdImage) {
                imagedestroy($oriented);
            }
        }
    }

    private function assertOrientationWithImagick(OrientationThumbnailServiceStub $service, int $orientation, array $storedLayout, array $expectedLayout): void
    {
        $imagick = $this->createImagickFromLayout($storedLayout);

        try {
            $service->orientImagickImage($imagick, $orientation);
            $actual = $this->extractLayoutFromImagick($imagick);

            $this->assertLayoutEquals($expectedLayout, $actual, 'Imagick');
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    private function createOrientationTestService(): OrientationThumbnailServiceStub
    {
        return new OrientationThumbnailServiceStub();
    }

    /**
     * @return list<list<string>>
     */
    private function createBaseOrientationLayout(): array
    {
        return [
            ['#ff0000', '#00ff00', '#0000ff'],
            ['#ffff00', '#ff00ff', '#00ffff'],
        ];
    }

    /**
     * @param list<list<string>> $layout
     * @param list<string>       $operations
     *
     * @return list<list<string>>
     */
    private function applyLayoutOperations(array $layout, array $operations): array
    {
        $result = $layout;

        foreach ($operations as $operation) {
            switch ($operation) {
                case 'flip-horizontal':
                    $result = $this->flipLayoutHorizontally($result);

                    break;
                case 'flip-vertical':
                    $result = $this->flipLayoutVertically($result);

                    break;
                case 'rotate-90':
                    $result = $this->rotateLayout90($result);

                    break;
                case 'rotate--90':
                    $result = $this->rotateLayoutMinus90($result);

                    break;
                case 'rotate-180':
                    $result = $this->rotateLayout180($result);

                    break;
            }
        }

        return $result;
    }

    /**
     * @param list<list<string>> $layout
     *
     * @return list<list<string>>
     */
    private function flipLayoutHorizontally(array $layout): array
    {
        $result = [];

        foreach ($layout as $row) {
            $result[] = array_values(array_reverse($row));
        }

        return $result;
    }

    /**
     * @param list<list<string>> $layout
     *
     * @return list<list<string>>
     */
    private function flipLayoutVertically(array $layout): array
    {
        $rows   = array_reverse($layout);
        $result = [];

        foreach ($rows as $row) {
            $result[] = array_values($row);
        }

        return $result;
    }

    /**
     * @param list<list<string>> $layout
     *
     * @return list<list<string>>
     */
    private function rotateLayout90(array $layout): array
    {
        $height = count($layout);
        $width  = count($layout[0]);
        $result = [];

        for ($x = $width - 1; $x >= 0; --$x) {
            $row = [];

            for ($y = 0; $y < $height; ++$y) {
                $row[] = $layout[$y][$x];
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param list<list<string>> $layout
     *
     * @return list<list<string>>
     */
    private function rotateLayoutMinus90(array $layout): array
    {
        $height = count($layout);
        $width  = count($layout[0]);
        $result = [];

        for ($x = 0; $x < $width; ++$x) {
            $row = [];

            for ($y = $height - 1; $y >= 0; --$y) {
                $row[] = $layout[$y][$x];
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param list<list<string>> $layout
     *
     * @return list<list<string>>
     */
    private function rotateLayout180(array $layout): array
    {
        $height = count($layout);
        $width  = count($layout[0]);
        $result = [];

        for ($y = $height - 1; $y >= 0; --$y) {
            $row = [];

            for ($x = $width - 1; $x >= 0; --$x) {
                $row[] = $layout[$y][$x];
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param list<list<string>> $layout
     */
    private function createGdImageFromLayout(array $layout): GdImage
    {
        $height = count($layout);
        $width  = count($layout[0]);

        $image = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $colorComponents = $this->hexToRgb($layout[$y][$x]);
                $colorResource   = imagecolorallocate($image, $colorComponents['r'], $colorComponents['g'], $colorComponents['b']);

                if (!is_int($colorResource)) {
                    imagedestroy($image);

                    self::fail('Unable to allocate GD color for orientation layout.');
                }

                $setPixelResult = imagesetpixel($image, $x, $y, $colorResource);

                if ($setPixelResult === false) {
                    imagedestroy($image);

                    self::fail('Unable to set GD pixel color for orientation layout.');
                }
            }
        }

        return $image;
    }

    /**
     * @return list<list<string>>
     */
    private function extractLayoutFromGdImage(GdImage $image): array
    {
        $height = imagesy($image);
        $width  = imagesx($image);
        $layout = [];

        for ($y = 0; $y < $height; ++$y) {
            $row = [];

            for ($x = 0; $x < $width; ++$x) {
                $colorIndex = imagecolorat($image, $x, $y);
                $color      = imagecolorsforindex($image, $colorIndex);

                $row[] = $this->rgbToHex($color['red'], $color['green'], $color['blue']);
            }

            $layout[] = $row;
        }

        return $layout;
    }

    /**
     * @param list<list<string>> $layout
     */
    private function createImagickFromLayout(array $layout): Imagick
    {
        $height = count($layout);
        $width  = count($layout[0]);

        $imagick = new Imagick();
        $imagick->newImage($width, $height, new ImagickPixel('white'));
        $imagick->setImageFormat('png');

        $iterator = $imagick->getPixelIterator();

        foreach ($iterator as $y => $row) {
            foreach ($row as $x => $pixel) {
                $pixel->setColor($layout[$y][$x]);
            }

            $iterator->syncIterator();
        }

        return $imagick;
    }

    /**
     * @return list<list<string>>
     */
    private function extractLayoutFromImagick(Imagick $imagick): array
    {
        $height = $imagick->getImageHeight();
        $width  = $imagick->getImageWidth();
        $layout = [];

        for ($y = 0; $y < $height; ++$y) {
            $row = [];

            for ($x = 0; $x < $width; ++$x) {
                $color = $imagick->getImagePixelColor($x, $y)->getColor(true);

                $row[] = $this->rgbToHex(
                    (int) round($color['r'] * 255),
                    (int) round($color['g'] * 255),
                    (int) round($color['b'] * 255),
                );
            }

            $layout[] = $row;
        }

        return $layout;
    }

    private function assertLayoutEquals(array $expected, array $actual, string $context): void
    {
        self::assertCount(count($expected), $actual, sprintf('%s oriented image height differs.', $context));

        foreach ($expected as $rowIndex => $expectedRow) {
            self::assertCount(count($expectedRow), $actual[$rowIndex], sprintf('%s oriented image width differs for row %d.', $context, $rowIndex));

            foreach ($expectedRow as $columnIndex => $expectedColor) {
                self::assertSame(
                    $expectedColor,
                    $actual[$rowIndex][$columnIndex],
                    sprintf('%s pixel differs at position (%d, %d).', $context, $columnIndex, $rowIndex),
                );
            }
        }
    }

    /**
     * @return array{r:int, g:int, b:int}
     */
    private function hexToRgb(string $hex): array
    {
        $normalized = ltrim($hex, '#');

        if (strlen($normalized) !== 6) {
            self::fail(sprintf('Invalid hexadecimal color "%s".', $hex));
        }

        return [
            'r' => (int) hexdec(substr($normalized, 0, 2)),
            'g' => (int) hexdec(substr($normalized, 2, 2)),
            'b' => (int) hexdec(substr($normalized, 4, 2)),
        ];
    }

    private function rgbToHex(int $red, int $green, int $blue): string
    {
        return sprintf('#%02x%02x%02x', $red, $green, $blue);
    }
}

final class OrientationThumbnailServiceStub extends ThumbnailService
{
    private string $orientationDir;

    public function __construct()
    {
        $this->orientationDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'memories-orientation-' . uniqid('', true);

        parent::__construct($this->orientationDir, [1]);
    }

    public function __destruct()
    {
        if (is_dir($this->orientationDir)) {
            @rmdir($this->orientationDir);
        }
    }

    public function orientGdImage(GdImage $image, ?int $orientation): GdImage
    {
        return $this->applyOrientationWithGd($image, $orientation);
    }

    public function orientImagickImage(Imagick $imagick, ?int $orientation): void
    {
        $this->applyOrientationWithImagick($imagick, $orientation);
    }
}
}
