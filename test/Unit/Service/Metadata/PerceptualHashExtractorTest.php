<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\PerceptualHashExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function file_put_contents;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagecreatefromjpeg;
use function imagedestroy;
use function imagefilledrectangle;
use function imagejpeg;
use function imagerotate;
use function is_file;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class PerceptualHashExtractorTest extends TestCase
{
    #[Test]
    public function computesStable128BitHashForImage(): void
    {
        $imagePath = $this->createGradientImage();

        try {
            $media = $this->makeMedia(
                id: 501,
                path: $imagePath,
                configure: static function (Media $media): void {
                    $media->setMime('image/jpeg');
                    $media->setWidth(64);
                    $media->setHeight(64);
                },
            );

            $extractor = new PerceptualHashExtractor(32, 16, 16);
            $extractor->extract($imagePath, $media);

            $phash = $media->getPhash();
            self::assertNotNull($phash);
            self::assertSame(32, strlen($phash ?? ''));
            self::assertSame('942a01442c9b996343c1becc632c9a7b', $phash);

            $prefix = $media->getPhashPrefix();
            self::assertSame(substr($phash, 0, 16), $prefix);

            $phash64 = $media->getPhash64();
            self::assertNotNull($phash64);
            self::assertMatchesRegularExpression('/^\d+$/', $phash64 ?? '');
        } finally {
            unlink($imagePath);
        }
    }

    #[Test]
    public function hashesVideoViaPosterFrame(): void
    {
        $videoBase = tempnam(sys_get_temp_dir(), 'vid_');
        if ($videoBase === false) {
            self::fail('Unable to create temporary video fixture.');
        }

        $videoPath = $videoBase . '.mp4';
        unlink($videoBase);

        // Placeholder payload – ffmpeg stub ignores contents.
        file_put_contents($videoPath, 'video');

        $ffmpeg  = $this->fixturePath('bin/mock-ffmpeg');
        $ffprobe = $this->fixturePath('bin/mock-ffprobe');

        try {
            $media = $this->makeMedia(
                id: 502,
                path: $videoPath,
                configure: static function (Media $media): void {
                    $media->setMime('video/mp4');
                    $media->setIsVideo(true);
                    $media->setWidth(1920);
                    $media->setHeight(1080);
                },
            );

            $extractor = new PerceptualHashExtractor(32, 16, 16, $ffmpeg, $ffprobe, 1.5);
            $extractor->extract($videoPath, $media);

            $phash = $media->getPhash();
            self::assertSame('d059af7420d10949cfd82a170af08d0d', $phash);
            self::assertSame(substr($phash, 0, 16), $media->getPhashPrefix());
            self::assertNotNull($media->getPhash64());
        } finally {
            if (is_file($videoPath)) {
                unlink($videoPath);
            }
        }
    }

    #[Test]
    public function producesIdenticalHashesAfterExifRotation(): void
    {
        $imagePath = $this->createGradientImage();
        $extractor = new PerceptualHashExtractor(32, 16, 16);

        try {
            $media = $this->makeMedia(
                id: 601,
                path: $imagePath,
                configure: static function (Media $entity): void {
                    $entity->setMime('image/jpeg');
                    $entity->setWidth(64);
                    $entity->setHeight(64);
                    $entity->setOrientation(1);
                },
            );

            $extractor->extract($imagePath, $media);
            $expectedPhash = $media->getPhash();
            self::assertNotNull($expectedPhash);

            $rotated90  = $this->createRotatedCopy($imagePath, -90);
            $rotated180 = $this->createRotatedCopy($imagePath, 180);

            try {
                $media90 = $this->makeMedia(
                    id: 602,
                    path: $rotated90,
                    configure: static function (Media $entity): void {
                        $entity->setMime('image/jpeg');
                        $entity->setWidth(64);
                        $entity->setHeight(64);
                        $entity->setOrientation(8);
                        $entity->setNeedsRotation(true);
                    },
                );

                $extractor->extract($rotated90, $media90);
                self::assertSame($expectedPhash, $media90->getPhash());

                $media180 = $this->makeMedia(
                    id: 603,
                    path: $rotated180,
                    configure: static function (Media $entity): void {
                        $entity->setMime('image/jpeg');
                        $entity->setWidth(64);
                        $entity->setHeight(64);
                        $entity->setOrientation(3);
                        $entity->setNeedsRotation(true);
                    },
                );

                $extractor->extract($rotated180, $media180);
                self::assertSame($expectedPhash, $media180->getPhash());
            } finally {
                unlink($rotated90);
                unlink($rotated180);
            }
        } finally {
            unlink($imagePath);
        }
    }

    private function createGradientImage(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'hash_');
        if ($base === false) {
            self::fail('Unable to create temporary image.');
        }

        $path = $base . '.jpg';
        unlink($base);

        $image = imagecreatetruecolor(64, 64);
        for ($y = 0; $y < 64; ++$y) {
            for ($x = 0; $x < 64; ++$x) {
                $gray  = (int) (($x + $y) % 256);
                $color = imagecolorallocate($image, $gray, $gray, $gray);
                imagefilledrectangle($image, $x, $y, $x, $y, $color);
            }
        }

        if (imagejpeg($image, $path, 90) !== true) {
            imagedestroy($image);
            self::fail('Unable to write gradient image.');
        }

        imagedestroy($image);

        return $path;
    }

    private function createRotatedCopy(string $sourcePath, int $degrees): string
    {
        $image = @imagecreatefromjpeg($sourcePath);
        if ($image === false) {
            self::fail('Unable to read source image for rotation.');
        }

        $rotated = @imagerotate($image, $degrees, 0);
        imagedestroy($image);

        if ($rotated === false) {
            self::fail('Unable to rotate image.');
        }

        $base = tempnam(sys_get_temp_dir(), 'rot_');
        if ($base === false) {
            imagedestroy($rotated);
            self::fail('Unable to create temporary rotated image.');
        }

        $path = $base . '.jpg';
        unlink($base);

        if (imagejpeg($rotated, $path, 90) !== true) {
            imagedestroy($rotated);
            self::fail('Unable to write rotated image.');
        }

        imagedestroy($rotated);

        return $path;
    }
}
