<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
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
            minAwayKm: 20.0,
            minNights: 2,
            timezone: 'UTC',
            minItemsPerDay: 3,
        );

        $dayTracks = [
            [
                ['lat' => 38.70, 'lon' => -9.14],
                ['lat' => 38.80, 'lon' => -9.24],
                ['lat' => 38.90, 'lon' => -9.34],
            ],
            [
                ['lat' => 39.00, 'lon' => -9.10],
                ['lat' => 39.10, 'lon' => -9.20],
                ['lat' => 39.20, 'lon' => -9.30],
            ],
            [
                ['lat' => 39.30, 'lon' => -9.05],
                ['lat' => 39.40, 'lon' => -9.15],
                ['lat' => 39.50, 'lon' => -9.25],
            ],
        ];

        $mediaItems = [];
        $start = new DateTimeImmutable('2023-07-01 08:00:00', new DateTimeZone('UTC'));
        $id = 70100;
        $perDayDistances = [];

        foreach ($dayTracks as $dayIndex => $points) {
            $dayStart = $start->add(new DateInterval('P' . $dayIndex . 'D'));
            $previous = null;
            $distanceKm = 0.0;

            foreach ($points as $offset => $coords) {
                $timestamp = $dayStart->add(new DateInterval('PT' . ($offset * 3) . 'H'));
                $mediaItems[] = $this->createMedia(++$id, $timestamp->format('Y-m-d H:i:00'), $coords['lat'], $coords['lon']);

                if ($previous !== null) {
                    $distanceKm += MediaMath::haversineDistanceInMeters(
                        $previous['lat'],
                        $previous['lon'],
                        $coords['lat'],
                        $coords['lon']
                    ) / 1000.0;
                }

                $previous = $coords;
            }

            $perDayDistances[] = $distanceKm;
        }

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('long_trip', $cluster->getAlgorithm());
        self::assertSame(range(70101, 70109), $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame(2, $params['nights']);

        self::assertArrayHasKey('distance_km', $params);
        $expectedAverageDistance = array_sum($perDayDistances) / count($perDayDistances);
        self::assertEqualsWithDelta($expectedAverageDistance, $params['distance_km'], 0.1);

        $centroid = $cluster->getCentroid();
        $expectedCentroid = MediaMath::centroid($mediaItems);
        self::assertEqualsWithDelta((float) $expectedCentroid['lat'], $centroid['lat'], 0.0001);
        self::assertEqualsWithDelta((float) $expectedCentroid['lon'], $centroid['lon'], 0.0001);
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
