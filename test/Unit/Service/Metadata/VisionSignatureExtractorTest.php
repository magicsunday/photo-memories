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

use function file_put_contents;
use function filesize;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagepng;
use function imagedestroy;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class VisionSignatureExtractorTest extends TestCase
{
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

            $log = $media->getIndexLog();
            self::assertNotNull($log);
            self::assertStringContainsString('clip=1.00', $log);
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

            $log = $media->getIndexLog();
            self::assertNotNull($log);
            self::assertStringContainsString('qlt=low', $log);
            self::assertStringContainsString('clip=1.00', $log);
        } finally {
            unlink($videoPath);
        }
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
}
