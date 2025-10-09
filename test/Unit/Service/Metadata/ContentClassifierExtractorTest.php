<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Entity\Enum\ContentKind;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\ContentClassifierExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

final class ContentClassifierExtractorTest extends TestCase
{
    #[Test]
    public function classifiesScreenshotsUsingFilenameAndVisionStats(): void
    {
        $media = $this->buildMedia(10, 'image/png', 1284, 2778);
        $media->setFeatures([
            'file' => [
                'pathTokens'   => ['2025', 'Screenshot'],
                'filenameHint' => 'normal',
            ],
        ]);
        $media->setSharpness(0.1);
        $media->setColorfulness(0.1);

        $extractor = new ContentClassifierExtractor();
        $extractor->extract('/library/Screenshot-2025-03-01.png', $media);

        self::assertSame(ContentKind::SCREENSHOT, $media->getContentKind());
        self::assertTrue($media->isNoShow());

        $bag = $media->getFeatureBag();
        self::assertSame(ContentKind::SCREENSHOT, $bag->classificationKind());
        self::assertNotNull($bag->classificationConfidence());
        self::assertTrue($bag->classificationShouldHide());
    }

    #[Test]
    public function classifiesDocumentsFromVisionStatistics(): void
    {
        $media = $this->buildMedia(11, 'image/jpeg', 2400, 3200);
        $media->setFeatures([
            'file' => ['pathTokens' => ['Scan']],
        ]);
        $media->setColorfulness(0.15);
        $media->setContrast(0.70);
        $media->setBrightness(0.85);

        $extractor = new ContentClassifierExtractor();
        $extractor->extract('/library/Scan-2025-Receipt.jpg', $media);

        self::assertSame(ContentKind::DOCUMENT, $media->getContentKind());
        self::assertTrue($media->isNoShow());

        $bag = $media->getFeatureBag();
        self::assertSame(ContentKind::DOCUMENT, $bag->classificationKind());
        self::assertNotNull($bag->classificationConfidence());
        self::assertTrue($bag->classificationShouldHide());
    }

    #[Test]
    public function detectsScreenRecordingsViaVideoMetadata(): void
    {
        $media = $this->buildMedia(12, 'video/mp4', 1920, 1080);
        $media->setIsVideo(true);
        $media->setVideoFps(60.0);
        $media->setFeatures([
            'file' => ['pathTokens' => ['Screenrecord']],
        ]);

        $extractor = new ContentClassifierExtractor();
        $extractor->extract('/library/Screenrecord-2025-Session.mp4', $media);

        self::assertSame(ContentKind::SCREEN_RECORDING, $media->getContentKind());
        self::assertTrue($media->isNoShow());

        $bag = $media->getFeatureBag();
        self::assertSame(ContentKind::SCREEN_RECORDING, $bag->classificationKind());
        self::assertNotNull($bag->classificationConfidence());
        self::assertTrue($bag->classificationShouldHide());
    }

    #[Test]
    public function marksMapCapturesBasedOnTokens(): void
    {
        $media = $this->buildMedia(13, 'image/png', 2048, 2048);
        $media->setFeatures([
            'file' => ['pathTokens' => ['City', 'Map']],
        ]);
        $media->setColorfulness(0.6);
        $media->setEntropy(0.5);

        $extractor = new ContentClassifierExtractor();
        $extractor->extract('/library/City-Map.png', $media);

        self::assertSame(ContentKind::MAP, $media->getContentKind());
        self::assertTrue($media->isNoShow());

        $bag = $media->getFeatureBag();
        self::assertSame(ContentKind::MAP, $bag->classificationKind());
        self::assertNotNull($bag->classificationConfidence());
        self::assertTrue($bag->classificationShouldHide());
    }

    #[Test]
    public function classifiesScreenshotsViaVisionTagsWhenTokensMissing(): void
    {
        $media = $this->buildMedia(14, 'image/png', 1242, 2688);
        $media->setSharpness(0.2);
        $media->setColorfulness(0.1);
        $media->setSceneTags([
            ['label' => 'Screen capture interface', 'score' => 0.88],
        ]);

        $extractor = new ContentClassifierExtractor();
        $extractor->extract('/library/Image-14.png', $media);

        self::assertSame(ContentKind::SCREENSHOT, $media->getContentKind());
        self::assertTrue($media->isNoShow());

        $bag = $media->getFeatureBag();
        self::assertSame(ContentKind::SCREENSHOT, $bag->classificationKind());
        self::assertNotNull($bag->classificationConfidence());
        self::assertTrue($bag->classificationShouldHide());
    }

    private function buildMedia(int $id, string $mime, int $width, int $height): Media
    {
        $media = new Media('path-' . $id, 'checksum-' . $id, 1024);
        $media->setMime($mime);
        $media->setWidth($width);
        $media->setHeight($height);

        $ref = new ReflectionProperty(Media::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($media, $id);

        return $media;
    }
}
