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
use MagicSunday\Memories\Service\Metadata\Quality\MediaQualityAggregator;
use MagicSunday\Memories\Service\Metadata\VisionSignatureExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

use function file_put_contents;
use function filesize;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagefilledrectangle;
use function imagepng;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class VisionSignatureExtractorTest extends TestCase
{
    #[Test]
    public function motionBlurScorePrefersSharpPatterns(): void
    {
        $extractor = new VisionSignatureExtractor(new MediaQualityAggregator(), 32);

        $reflection = new ReflectionClass($extractor);
        $method     = $reflection->getMethod('motionBlurScore');
        $method->setAccessible(true);

        $sharpMatrix   = $this->createCheckerboardMatrix(32, 32, 2);
        $blurredMatrix = $this->applyGaussianBlur($sharpMatrix, 6);

        $sharpScore   = $method->invoke($extractor, $sharpMatrix);
        $blurredScore = $method->invoke($extractor, $blurredMatrix);

        self::assertIsFloat($sharpScore);
        self::assertIsFloat($blurredScore);
        self::assertGreaterThan($blurredScore, $sharpScore);
        self::assertGreaterThan(0.5, $sharpScore);
        self::assertLessThan(0.85, $blurredScore);
        self::assertGreaterThan(0.2, $sharpScore - $blurredScore);
    }

    #[Test]
    public function populatesMissingDimensionsAndAspectFlags(): void
    {
        $imagePath = $this->createImageWithDimensions(2400, 800);

        try {
            $media = $this->makeMedia(
                id: 103,
                path: $imagePath,
                configure: static function (Media $media): void {
                    $media->setMime('image/png');
                },
                size: (int) filesize($imagePath),
            );

            $extractor = new VisionSignatureExtractor(new MediaQualityAggregator(), 16);
            $extractor->extract($imagePath, $media);

            self::assertSame(2400, $media->getWidth());
            self::assertSame(800, $media->getHeight());
            self::assertFalse($media->isPortrait());
            self::assertTrue($media->isPanorama());
        } finally {
            unlink($imagePath);
        }
    }

    #[Test]
    public function respectsOrientationWhenDerivingPortraitFlag(): void
    {
        $imagePath = $this->createImageWithDimensions(2400, 800);

        try {
            $media = $this->makeMedia(
                id: 104,
                path: $imagePath,
                configure: static function (Media $media): void {
                    $media->setMime('image/png');
                    $media->setOrientation(6);
                },
                size: (int) filesize($imagePath),
            );

            $extractor = new VisionSignatureExtractor(new MediaQualityAggregator(), 16);
            $extractor->extract($imagePath, $media);

            self::assertTrue($media->isPortrait());
            self::assertFalse($media->isPanorama());
        } finally {
            unlink($imagePath);
        }
    }

    #[Test]
    public function computesClippingForSaturatedImage(): void
    {
        $imagePath = $this->createSaturatedImage();

        try {
            $media = $this->makeMedia(
                id: 101,
                path: $imagePath,
                configure: function (Media $media): void {
                    $media->setMime('image/png');
                    $media->setWidth(4000);
                    $media->setHeight(3000);
                    $media->setIso(100);
                },
                size: (int) filesize($imagePath),
            );

            $extractor = new VisionSignatureExtractor(new MediaQualityAggregator(), 16);
            $extractor->extract($imagePath, $media);

            self::assertNotNull($media->getQualityClipping());
            self::assertEqualsWithDelta(1.0, $media->getQualityClipping() ?? 0.0, 0.0005);
            self::assertTrue($media->isLowQuality());

            $entries = $this->decodeIndexLog($media->getIndexLog());
            self::assertNotSame([], $entries);
            self::assertEqualsWithDelta(1.0, $entries[0]['context']['clipping'] ?? 0.0, 0.0001);
        } finally {
            unlink($imagePath);
        }
    }

    #[Test]
    public function usesPosterFrameForVideo(): void
    {
        $videoBase = tempnam(sys_get_temp_dir(), 'vid_');
        if ($videoBase === false) {
            self::fail('Unable to create temporary video fixture.');
        }

        $videoPath = $videoBase . '.mp4';
        unlink($videoBase);
        file_put_contents($videoPath, 'dummy');

        $ffmpeg  = $this->fixturePath('bin/mock-ffmpeg');
        $ffprobe = $this->fixturePath('bin/mock-ffprobe');

        try {
            $media = $this->makeMedia(
                id: 102,
                path: $videoPath,
                configure: function (Media $media): void {
                    $media->setMime('video/mp4');
                    $media->setIsVideo(true);
                    $media->setWidth(3840);
                    $media->setHeight(2160);
                    $media->setIso(100);
                },
                size: (int) filesize($videoPath),
            );

            $extractor = new VisionSignatureExtractor(
                new MediaQualityAggregator(),
                16,
                $ffmpeg,
                $ffprobe,
                1.0,
            );

            $extractor->extract($videoPath, $media);

            self::assertNotNull($media->getQualityClipping());
            self::assertEqualsWithDelta(1.0, $media->getQualityClipping() ?? 0.0, 0.0005);
            self::assertTrue($media->isLowQuality());

            $entries = $this->decodeIndexLog($media->getIndexLog());
            self::assertNotSame([], $entries);
            self::assertSame('low', $entries[0]['context']['status'] ?? null);
            self::assertEqualsWithDelta(1.0, $entries[0]['context']['clipping'] ?? 0.0, 0.0001);
        } finally {
            unlink($videoPath);
        }
    }

    private function createImageWithDimensions(int $width, int $height): string
    {
        $base = tempnam(sys_get_temp_dir(), 'img_');
        if ($base === false) {
            self::fail('Unable to create temporary image fixture.');
        }

        $path = $base . '.png';
        unlink($base);

        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 64, 128, 192);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);

        if (imagepng($image, $path) !== true) {
            imagedestroy($image);
            self::fail('Unable to write image fixture.');
        }

        imagedestroy($image);

        return $path;
    }

    private function createSaturatedImage(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'img_');
        if ($base === false) {
            self::fail('Unable to create temporary image fixture.');
        }

        $path = $base . '.png';
        unlink($base);

        $image = imagecreatetruecolor(8, 8);
        $red   = imagecolorallocate($image, 255, 0, 0);
        imagefilledrectangle($image, 0, 0, 7, 7, $red);

        if (imagepng($image, $path) !== true) {
            imagedestroy($image);
            self::fail('Unable to write saturated image fixture.');
        }

        imagedestroy($image);

        return $path;
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function createCheckerboardMatrix(int $width, int $height, int $blockSize): array
    {
        $matrix = [];

        for ($y = 0; $y < $height; ++$y) {
            $row = [];
            for ($x = 0; $x < $width; ++$x) {
                $tileX = (int) ($x / $blockSize);
                $tileY = (int) ($y / $blockSize);
                $row[] = (($tileX + $tileY) % 2 === 0) ? 255.0 : 0.0;
            }

            $matrix[] = $row;
        }

        return $matrix;
    }

    /**
     * @param array<int, array<int, float>> $matrix
     *
     * @return array<int, array<int, float>>
     */
    private function applyGaussianBlur(array $matrix, int $iterations = 3): array
    {
        $height = count($matrix);
        $width  = $height > 0 ? count($matrix[0]) : 0;

        $kernel = [
            [1.0, 2.0, 1.0],
            [2.0, 4.0, 2.0],
            [1.0, 2.0, 1.0],
        ];

        for ($i = 0; $i < $iterations; ++$i) {
            $result = [];

            for ($y = 0; $y < $height; ++$y) {
                $row = [];

                for ($x = 0; $x < $width; ++$x) {
                    $weightedSum = 0.0;
                    $weightTotal = 0.0;

                    for ($ky = -1; $ky <= 1; ++$ky) {
                        $sampleY = $y + $ky;
                        if ($sampleY < 0 || $sampleY >= $height) {
                            continue;
                        }

                        for ($kx = -1; $kx <= 1; ++$kx) {
                            $sampleX = $x + $kx;
                            if ($sampleX < 0 || $sampleX >= $width) {
                                continue;
                            }

                            $kernelValue = $kernel[$ky + 1][$kx + 1];
                            $weightedSum += $matrix[$sampleY][$sampleX] * $kernelValue;
                            $weightTotal += $kernelValue;
                        }
                    }

                    if ($weightTotal <= 0.0) {
                        $row[] = $matrix[$y][$x];
                    } else {
                        $row[] = $weightedSum / $weightTotal;
                    }
                }

                $result[] = $row;
            }

            $matrix = $result;
        }

        return $matrix;
    }
}
