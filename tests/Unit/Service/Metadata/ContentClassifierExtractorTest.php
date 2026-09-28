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
            'file' => ['pathTokens' => ['Users', 'foo', 'Scan']],
        ]);
        $media->setColorfulness(0.15);
        $media->setContrast(0.70);
        $media->setBrightness(0.85);

        $extractor = new ContentClassifierExtractor();
        $extractor->extract('/Users/foo/Scan/Scan-2025-Receipt.jpg', $media);

        self::assertSame(ContentKind::DOCUMENT, $media->getContentKind());
        self::assertTrue($media->isNoShow());

        $bag = $media->getFeatureBag();
        self::assertSame(ContentKind::DOCUMENT, $bag->classificationKind());
        self::assertNotNull($bag->classificationConfidence());
        self::assertTrue($bag->classificationShouldHide());
    }

    #[Test]
    public function classifiesDocumentsBasedOnInvoiceFilename(): void
    {
        $media = $this->buildMedia(215, 'image/jpeg', 2000, 2600);
        $media->setFeatures([
            'file' => ['pathTokens' => ['Archive', 'Tax']],
        ]);
        $media->setColorfulness(0.18);
        $media->setContrast(0.66);
        $media->setBrightness(0.86);

        $extractor = new ContentClassifierExtractor();
        $extractor->extract('/archive/2024/invoice_2024.jpg', $media);

        self::assertSame(ContentKind::DOCUMENT, $media->getContentKind());

        $bag = $media->getFeatureBag();
        self::assertSame(ContentKind::DOCUMENT, $bag->classificationKind());
    }

    #[Test]
    public function classifiesDocumentsBasedOnLowColorAndBrightnessExtremes(): void
    {
        $media = $this->buildMedia(21, 'image/jpeg', 2000, 2600);
        $media->setColorfulness(0.12);
        $media->setContrast(0.62);
        $media->setBrightness(0.88);

        $extractor = new ContentClassifierExtractor();
        $extractor->extract('/library/Document-21.jpg', $media);

        self::assertSame(ContentKind::DOCUMENT, $media->getContentKind());
        self::assertTrue($media->isNoShow());

        $bag = $media->getFeatureBag();
        self::assertSame(ContentKind::DOCUMENT, $bag->classificationKind());
        $confidence = $bag->classificationConfidence();
        self::assertNotNull($confidence);
        self::assertGreaterThanOrEqual(0.5, $confidence);
    }

    #[Test]
    public function ignoresGenericDocumentDirectoryWithoutDocumentSignals(): void
    {
        $media = $this->buildMedia(224, 'image/jpeg', 4032, 3024);
        $media->setFeatures([
            'file' => ['pathTokens' => ['Users', 'foo', 'Documents']],
        ]);
        $media->setColorfulness(0.52);
        $media->setContrast(0.48);
        $media->setBrightness(0.50);

        $extractor = new ContentClassifierExtractor();
        $extractor->extract('/Users/foo/Documents/IMG_0001.JPG', $media);

        self::assertNull($media->getContentKind());

        $bag = $media->getFeatureBag();
        self::assertNull($bag->classificationKind());
    }

    #[Test]
    public function doesNotClassifyRegularPhotoWithBalancedMetrics(): void
    {
        $media = $this->buildMedia(22, 'image/jpeg', 4000, 3000);
        $media->setColorfulness(0.48);
        $media->setContrast(0.58);
        $media->setBrightness(0.52);

        $extractor = new ContentClassifierExtractor();
        $extractor->extract('/library/IMG_0022.jpg', $media);

        self::assertNull($media->getContentKind());
        self::assertFalse($media->isNoShow());

        $bag = $media->getFeatureBag();
        self::assertNull($bag->classificationKind());
        self::assertNull($bag->classificationConfidence());
        self::assertNull($bag->classificationShouldHide());
    }

    #[Test]
    public function ignoresHighContrastWithoutFurtherDocumentSignals(): void
    {
        $media = $this->buildMedia(23, 'image/jpeg', 4032, 3024);
        $media->setColorfulness(0.35);
        $media->setContrast(0.75);
        $media->setBrightness(0.55);

        $extractor = new ContentClassifierExtractor();
        $extractor->extract('/library/DSC_1023.jpg', $media);

        self::assertNull($media->getContentKind());
        self::assertFalse($media->isNoShow());

        $bag = $media->getFeatureBag();
        self::assertNull($bag->classificationKind());
        self::assertNull($bag->classificationConfidence());
        self::assertNull($bag->classificationShouldHide());
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

        $this->assignEntityId($media, $id);

        return $media;
    }
}
