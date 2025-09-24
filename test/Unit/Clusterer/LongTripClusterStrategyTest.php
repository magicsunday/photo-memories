<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\LongTripClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LongTripClusterStrategyTest extends TestCase
{
    #[Test]
    public function aggregatesActualDistanceForAwayRun(): void
    {
        $strategy = new LongTripClusterStrategy(
            homeLat: 52.5200,
            homeLon: 13.4050,
            minAwayKm: 150.0,
            minNights: 2,
            timezone: 'UTC',
            minItemsPerDay: 3,
        );

        $lisbonLat = 38.7223;
        $lisbonLon = -9.1393;

        $mediaItems = [
            // Day 1
            $this->createMedia(70101, '2023-07-01 08:30:00', $lisbonLat, $lisbonLon),
            $this->createMedia(70102, '2023-07-01 12:15:00', $lisbonLat, $lisbonLon),
            $this->createMedia(70103, '2023-07-01 19:45:00', $lisbonLat, $lisbonLon),
            // Day 2
            $this->createMedia(70111, '2023-07-02 09:10:00', $lisbonLat, $lisbonLon),
            $this->createMedia(70112, '2023-07-02 14:20:00', $lisbonLat, $lisbonLon),
            $this->createMedia(70113, '2023-07-02 22:05:00', $lisbonLat, $lisbonLon),
            // Day 3
            $this->createMedia(70121, '2023-07-03 09:00:00', $lisbonLat, $lisbonLon),
            $this->createMedia(70122, '2023-07-03 13:30:00', $lisbonLat, $lisbonLon),
            $this->createMedia(70123, '2023-07-03 20:15:00', $lisbonLat, $lisbonLon),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('long_trip', $cluster->getAlgorithm());
        self::assertSame([
            70101, 70102, 70103,
            70111, 70112, 70113,
            70121, 70122, 70123,
        ], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame(2, $params['nights']);

        $expectedDistanceKm = MediaMath::haversineDistanceInMeters(
                $lisbonLat,
                $lisbonLon,
                52.5200,
                13.4050
            ) / 1000.0;

        self::assertArrayHasKey('distance_km', $params);
        self::assertGreaterThan(150.0, $params['distance_km']);
        self::assertEqualsWithDelta($expectedDistanceKm, $params['distance_km'], 0.1);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta($lisbonLat, $centroid['lat'], 0.0001);
        self::assertEqualsWithDelta($lisbonLon, $centroid['lon'], 0.0001);
    }

    #[Test]
    public function skipsDaysWithoutEnoughGpsSamples(): void
    {
        $strategy = new LongTripClusterStrategy(
            homeLat: 52.5200,
            homeLon: 13.4050,
            minAwayKm: 150.0,
            minNights: 2,
            timezone: 'UTC',
            minItemsPerDay: 3,
        );

        $lisbonLat = 38.7223;
        $lisbonLon = -9.1393;

        $mediaItems = [
            // Day 1 - plenty of GPS samples
            $this->createMedia(80101, '2023-09-10 09:00:00', $lisbonLat, $lisbonLon),
            $this->createMedia(80102, '2023-09-10 13:00:00', $lisbonLat, $lisbonLon),
            $this->createMedia(80103, '2023-09-10 19:00:00', $lisbonLat, $lisbonLon),
            // Day 2 - only a single GPS point, rest without coordinates
            $this->createMedia(80111, '2023-09-11 10:00:00', $lisbonLat, $lisbonLon),
            $this->createMedia(80112, '2023-09-11 12:00:00'),
            $this->createMedia(80113, '2023-09-11 15:00:00'),
            $this->createMedia(80114, '2023-09-11 18:00:00'),
            // Day 3 - also away from home
            $this->createMedia(80121, '2023-09-12 09:00:00', $lisbonLat, $lisbonLon),
            $this->createMedia(80122, '2023-09-12 14:00:00', $lisbonLat, $lisbonLon),
            $this->createMedia(80123, '2023-09-12 20:00:00', $lisbonLat, $lisbonLon),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(
        int $id,
        string $takenAt,
        ?float $lat = null,
        ?float $lon = null
    ): Media {
        $media = new Media(
            path: __DIR__ . "/fixtures/long-trip-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 2048,
        );

        $this->assignId($media, $id);
        $media->setTakenAt(new DateTimeImmutable($takenAt, new DateTimeZone('UTC')));

        if ($lat !== null) {
            $media->setGpsLat($lat);
        }

        if ($lon !== null) {
            $media->setGpsLon($lon);
        }

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
