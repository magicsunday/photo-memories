<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\Service\Metadata;

use MagicSunday\Memories\Clusterer\Contract\TimezoneResolverInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\CompositeMetadataExtractor;
use MagicSunday\Memories\Service\Metadata\DaypartEnricher;
use MagicSunday\Memories\Service\Metadata\GeoFeatureEnricher;
use MagicSunday\Memories\Service\Metadata\MetadataExtractorPipelineConfiguration;
use MagicSunday\Memories\Service\Metadata\MetadataExtractorTelemetry;
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;
use MagicSunday\Memories\Service\Metadata\Support\CaptureTimeResolver;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\MediaMath;
use MagicSunday\Memories\Utility\S2CellId;
use PHPUnit\Framework\Attributes\Test;
use function file_put_contents;
use function is_file;
use function rename;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Integration guard for the composite metadata pipeline.
 *
 * Verified fields are consumed by:
 * - AbstractAtHomeClusterStrategy (distanceKmFromHome/geoCell)
 * - NightlifeEventClusterStrategy & GoldenHourClusterStrategy (features['calendar']['daypart'], sceneTags, keywords)
 * - ClusterQualityAggregator (quality metrics)
 * - PersonCohortClusterStrategy & ClusterBuildHelperTrait (persons/keywords)
 *
 * Heavyweight services replaced with fakes in this test:
 * - VisionSignatureExtractor (FFmpeg/Imagick) -> FakeVisionSignatureExtractor
 * - ClipSceneTagExtractor (ML scene model) -> FakeSceneTagExtractor
 * - FacePresenceDetector (face backend) -> FakeFacePresenceExtractor
 * - XmpIptc/Filename extractors -> FakeKeywordAndPersonExtractor
 */
final class CompositeMetadataExtractorClusterFieldsTest extends TestCase
{
    #[Test]
    public function enrichesClusterCriticalFieldsWithoutRealBackends(): void
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'media_');
        if ($tmpBase === false) {
            self::fail('Unable to create temporary media file.');
        }

        $imagePath = $tmpBase . '.jpg';
        if (@rename($tmpBase, $imagePath) === false) {
            @unlink($tmpBase);
            self::fail('Unable to prepare temporary media file.');
        }

        if (file_put_contents($imagePath, 'jpg') === false) {
            @unlink($imagePath);
            self::fail('Unable to seed temporary media file.');
        }

        $homeLat = 48.137154; // Marienplatz, München
        $homeLon = 11.576124;

        $timezoneResolver   = new FakeTimezoneResolver();
        $captureTime        = new CaptureTimeResolver($timezoneResolver);
        $daypartEnricher    = new DaypartEnricher($captureTime);
        $geoEnricher        = new GeoFeatureEnricher($homeLat, $homeLon, 1.0, 'v1', 0.01);
        $qualityExtractor   = new FakeVisionSignatureExtractor();
        $sceneExtractor     = new FakeSceneTagExtractor();
        $keywordPersonStage = new FakeKeywordAndPersonExtractor();
        $faceExtractor      = new FakeFacePresenceExtractor();

        $composite = new CompositeMetadataExtractor([
            $keywordPersonStage,
            $faceExtractor,
            $daypartEnricher,
            $geoEnricher,
            $qualityExtractor,
            $sceneExtractor,
        ], new MetadataExtractorPipelineConfiguration([], false), new MetadataExtractorTelemetry());

        $media = $this->makeMedia(
            id: 910,
            path: $imagePath,
            takenAt: '2024-05-04 18:30:00',
            lat: 48.123400,
            lon: 11.567800,
            configure: static function (Media $media): void {
                $media->setMime('image/jpeg');
                $media->setWidth(4032);
                $media->setHeight(3024);
            },
        );

        try {
            $processed = $composite->extract($imagePath, $media);

            $this->assertSame($media, $processed);

            $distanceKm = MediaMath::haversineDistanceInMeters(
                $homeLat,
                $homeLon,
                48.123400,
                11.567800,
            ) / 1000.0;

            self::assertEqualsWithDelta($distanceKm, $processed->getDistanceKmFromHome(), 0.05);
            self::assertSame('48.1200,11.5600', $processed->getGeoCell8());
            self::assertNotNull($processed->getGeohash7());
            self::assertSame(
                S2CellId::tokenFromDegrees(48.123400, 11.567800, 12),
                $processed->getS2CellId(),
            );
            self::assertTrue($processed->needsGeocode());

            $features = $processed->getFeatures();
            self::assertIsArray($features);
            self::assertArrayHasKey('calendar', $features);
            self::assertSame('evening', $features['calendar']['daypart'] ?? null);

            $sceneTags = $processed->getSceneTags();
            self::assertIsArray($sceneTags);
            self::assertSame([
                ['label' => 'sunset', 'score' => 0.92],
                ['label' => 'city skyline', 'score' => 0.74],
            ], $sceneTags);

            self::assertFalse($processed->isLowQuality());
            self::assertSame(0.85, $processed->getQualityScore());
            self::assertSame(0.70, $processed->getSharpness());
            self::assertSame(0.65, $processed->getBrightness());
            self::assertSame(0.72, $processed->getContrast());
            self::assertSame(0.68, $processed->getEntropy());
            self::assertSame(0.50, $processed->getColorfulness());
            self::assertSame(0.10, $processed->getQualityClipping());
            self::assertSame(0.77, $processed->getQualityExposure());
            self::assertSame(0.66, $processed->getQualityNoise());

            self::assertTrue($processed->hasFaces());
            self::assertSame(2, $processed->getFacesCount());

            self::assertSame([
                'Alice Example',
                'Bob Sample',
            ], $processed->getPersons());

            self::assertSame([
                'sunset',
                'vacation',
            ], $processed->getKeywords());
        } finally {
            if (is_file($imagePath)) {
                unlink($imagePath);
            }
        }
    }
}

final class FakeTimezoneResolver implements TimezoneResolverInterface
{
    public function resolveMediaTimezone(Media $media, \DateTimeImmutable $takenAt, array $home): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Berlin');
    }

    public function resolveSummaryTimezone(array $summary, array $home): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Berlin');
    }

    public function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int
    {
        return 120;
    }

    public function determineLocalTimezoneIdentifier(array $identifierVotes, array $home, ?int $offset): string
    {
        return 'Europe/Berlin';
    }
}

final class FakeVisionSignatureExtractor implements SingleMetadataExtractorInterface
{
    public function supports(string $filepath, Media $media): bool
    {
        return true;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $media->setQualityScore(0.85);
        $media->setSharpness(0.70);
        $media->setBrightness(0.65);
        $media->setContrast(0.72);
        $media->setEntropy(0.68);
        $media->setColorfulness(0.50);
        $media->setQualityClipping(0.10);
        $media->setQualityExposure(0.77);
        $media->setQualityNoise(0.66);
        $media->setLowQuality(false);

        return $media;
    }
}

final class FakeSceneTagExtractor implements SingleMetadataExtractorInterface
{
    public function supports(string $filepath, Media $media): bool
    {
        return true;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $media->setSceneTags([
            ['label' => 'sunset', 'score' => 0.92],
            ['label' => 'city skyline', 'score' => 0.74],
        ]);

        return $media;
    }
}

final class FakeKeywordAndPersonExtractor implements SingleMetadataExtractorInterface
{
    public function supports(string $filepath, Media $media): bool
    {
        return true;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $media->setPersons([
            'Alice Example',
            'Bob Sample',
        ]);

        $media->setKeywords([
            'sunset',
            'vacation',
        ]);

        return $media;
    }
}

final class FakeFacePresenceExtractor implements SingleMetadataExtractorInterface
{
    public function supports(string $filepath, Media $media): bool
    {
        return true;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $media->setHasFaces(true);
        $media->setFacesCount(2);

        return $media;
    }
}
