<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\RoadTripClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class RoadTripClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersConsecutiveTravelDaysAboveDistanceThreshold(): void
    {
        $strategy = new RoadTripClusterStrategy(
            timezone: 'Europe/Berlin',
            minDailyKm: 80.0,
            minItemsPerDay: 3,
            minNights: 2,
            minItemsTotal: 12,
        );

        $start = new DateTimeImmutable('2023-07-01 08:00:00', new DateTimeZone('UTC'));
        $media = [];

        $days = [
            ['lat' => 52.5200, 'lon' => 13.4050], // Berlin
            ['lat' => 51.1657, 'lon' => 14.9885], // Bautzen area
            ['lat' => 50.1109, 'lon' => 8.6821],  // Frankfurt
        ];

        $id = 1000;
        foreach ($days as $index => $coords) {
            $dayStart = $start->add(new DateInterval('P' . $index . 'D'));
            $media = [...$media, ...$this->createDailyTrack($id, $dayStart, $coords['lat'], $coords['lon'])];
            $id += 4;
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('road_trip', $cluster->getAlgorithm());
        self::assertCount(12, $cluster->getMembers());
        self::assertSame(2, $cluster->getParams()['nights']);

        $timeRange = $cluster->getParams()['time_range'];
        self::assertSame($media[0]->getTakenAt()?->getTimestamp(), $timeRange['from']);
        self::assertSame(end($media)->getTakenAt()?->getTimestamp(), $timeRange['to']);
    }

    #[Test]
    public function rejectsRunsBelowDistanceRequirement(): void
    {
        $strategy = new RoadTripClusterStrategy(
            timezone: 'Europe/Berlin',
            minDailyKm: 500.0,
            minItemsPerDay: 3,
            minNights: 2,
            minItemsTotal: 12,
        );

        $start = new DateTimeImmutable('2023-08-10 09:00:00', new DateTimeZone('UTC'));
        $media = [];

        $coords = [
            ['lat' => 52.5200, 'lon' => 13.4050],
            ['lat' => 52.5300, 'lon' => 13.4100],
            ['lat' => 52.5400, 'lon' => 13.4150],
        ];

        $id = 2000;
        foreach ($coords as $index => $pos) {
            $dayStart = $start->add(new DateInterval('P' . $index . 'D'));
            $media = [...$media, ...$this->createDailyTrack($id, $dayStart, $pos['lat'], $pos['lon'])];
            $id += 4;
        }

        self::assertSame([], $strategy->cluster($media));
    }

    /**
     * @return list<Media>
     */
    private function createDailyTrack(int $startId, DateTimeImmutable $dayStart, float $lat, float $lon): array
    {
        $points = [
            [$lat, $lon],
            [$lat + 0.3, $lon + 0.3],
            [$lat + 0.6, $lon + 0.6],
            [$lat + 0.9, $lon + 0.9],
        ];

        $out = [];
        foreach ($points as $offset => $pair) {
            $out[] = $this->createMedia(
                $startId + $offset,
                $dayStart->add(new DateInterval('PT' . (2 * $offset) . 'H')),
                $pair[0],
                $pair[1],
            );
        }

        return $out;
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . "/fixtures/road-trip-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setGpsLat($lat);
        $media->setGpsLon($lon);

        return $media;
    }

}
