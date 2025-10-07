<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata\Quality;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Quality\MediaQualityAggregator;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

use function explode;
use function json_decode;
use function log;

use const JSON_THROW_ON_ERROR;

final class MediaQualityAggregatorTest extends TestCase
{
    #[Test]
    public function aggregatesQualityMetrics(): void
    {
        $media = $this->createMedia(1);
        $media->setWidth(6000);
        $media->setHeight(4000);
        $media->setSharpness(0.9);
        $media->setIso(100);
        $media->setBrightness(0.55);
        $media->setContrast(0.65);

        $aggregator = new MediaQualityAggregator();
        $aggregator->aggregate($media);

        self::assertNotNull($media->getQualityScore());
        self::assertEqualsWithDelta(0.8794, $media->getQualityScore(), 0.0005);
        self::assertEqualsWithDelta(0.86, $media->getQualityExposure(), 0.0005);
        self::assertEqualsWithDelta(0.8571, $media->getQualityNoise(), 0.0005);
        self::assertFalse($media->isLowQuality());
    }

    #[Test]
    public function flagsLowQualityMediaWhenThresholdsViolated(): void
    {
        $media = $this->createMedia(2);
        $media->setWidth(800);
        $media->setHeight(600);
        $media->setSharpness(0.2);
        $media->setIso(3200);
        $media->setBrightness(0.10);
        $media->setContrast(0.20);

        $aggregator = new MediaQualityAggregator();
        $aggregator->aggregate($media);

        self::assertTrue($media->isLowQuality());
        self::assertEqualsWithDelta(0.1526, $media->getQualityScore(), 0.0005);
        self::assertEqualsWithDelta(0.08, $media->getQualityExposure(), 0.0005);
        self::assertEqualsWithDelta(0.1429, $media->getQualityNoise(), 0.0005);
    }

    #[Test]
    public function marksHighQualitySignalsAsLowWhenResolutionTooSmall(): void
    {
        $media = $this->createMedia(4);
        $media->setWidth(1200);
        $media->setHeight(1600);
        $media->setSharpness(0.9);
        $media->setIso(100);
        $media->setBrightness(0.55);
        $media->setContrast(0.65);

        $aggregator = new MediaQualityAggregator();
        $aggregator->aggregate($media);

        self::assertNotNull($media->getQualityScore());
        self::assertTrue($media->isLowQuality());
    }

    #[Test]
    public function appliesClippingPenaltyAndLogsSummary(): void
    {
        $media = $this->createMedia(3);
        $media->setWidth(6000);
        $media->setHeight(4000);
        $media->setSharpness(0.6);
        $media->setIso(200);
        $media->setBrightness(0.55);
        $media->setContrast(0.5);
        $media->setQualityClipping(0.2);
        $media->setIndexLog('time=exif; tz=UTC; off=+0');

        $aggregator = new MediaQualityAggregator();
        $aggregator->aggregate($media);

        $expectedNoise = 1.0 - (log(200.0 / 50.0) / log(6400.0 / 50.0));
        $baseScore     = (0.6 * 0.50) + (0.8 * 0.30) + ($expectedNoise * 0.20);
        $expectedScore = $baseScore * (1.0 - (0.5 * 0.2));

        self::assertEqualsWithDelta($expectedScore, $media->getQualityScore() ?? 0.0, 0.0005);
        self::assertTrue($media->isLowQuality());
        self::assertEqualsWithDelta(0.2, $media->getQualityClipping() ?? 0.0, 0.0005);

        $logLines = explode("\n", (string) $media->getIndexLog());
        self::assertSame('time=exif; tz=UTC; off=+0', $logLines[0]);
        $entry = json_decode($logLines[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('low', $entry['context']['status'] ?? null);
        self::assertSame(0.2, $entry['context']['clipping'] ?? null);
        self::assertEqualsWithDelta(0.25, $entry['context']['noiseThreshold'] ?? 0.0, 0.0001);
    }

    #[Test]
    public function relaxesNoiseThresholdForLegacyCaptures(): void
    {
        $media = $this->createMedia(5);
        $media->setWidth(6000);
        $media->setHeight(4000);
        $media->setSharpness(0.6);
        $media->setIso(3200);
        $media->setBrightness(0.55);
        $media->setContrast(0.65);
        $media->setTakenAt(new DateTimeImmutable('2000-06-01T12:00:00+00:00'));

        $aggregator = new MediaQualityAggregator();
        $aggregator->aggregate($media);

        self::assertFalse($media->isLowQuality());

        $noiseScore = $media->getQualityNoise();
        self::assertNotNull($noiseScore);
        self::assertGreaterThan(0.14, $noiseScore);

        $entries = $this->decodeIndexLog($media->getIndexLog());
        self::assertSame('ok', $entries[0]['context']['status'] ?? null);
        self::assertEqualsWithDelta(0.10, $entries[0]['context']['noiseThreshold'] ?? 0.0, 0.0001);
    }

    private function createMedia(int $id): Media
    {
        $media = new Media(path: 'media-' . $id . '.jpg', checksum: 'checksum-' . $id, size: 1024);

        $ref = new ReflectionProperty(Media::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($media, $id);

        return $media;
    }
}
