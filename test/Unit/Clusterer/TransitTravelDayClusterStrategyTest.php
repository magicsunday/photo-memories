<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\TransitTravelDayClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransitTravelDayClusterStrategyTest extends TestCase
{
    #[Test]
    public function marksDaysWithSufficientTravelDistance(): void
    {
        $strategy = new TransitTravelDayClusterStrategy(
            timezone: 'Europe/Berlin',
            minTravelKm: 60.0,
            minGpsSamples: 5,
        );

        $day = new DateTimeImmutable('2024-07-01 06:00:00', new DateTimeZone('UTC'));
        $points = [
            [50.0, 8.0],
            [50.3, 8.5],
            [50.6, 9.0],
            [50.9, 9.5],
            [51.2, 10.0],
        ];

        $items = [];
        foreach ($points as $idx => [$lat, $lon]) {
            $items[] = $this->createMedia(2300 + $idx, $day->add(new DateInterval('PT' . ($idx * 1800) . 'S')), $lat, $lon);
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('transit_travel_day', $cluster->getAlgorithm());
        self::assertSame(range(2300, 2304), $cluster->getMembers());
        self::assertGreaterThanOrEqual(60.0, $cluster->getParams()['distance_km']);
    }

    #[Test]
    public function skipsDaysBelowDistance(): void
    {
        $strategy = new TransitTravelDayClusterStrategy();

        $day = new DateTimeImmutable('2024-07-02 06:00:00', new DateTimeZone('UTC'));
        $points = [
            [50.0, 8.0],
            [50.01, 8.01],
            [50.02, 8.02],
            [50.03, 8.03],
            [50.04, 8.04],
        ];

        $items = [];
        foreach ($points as $idx => [$lat, $lon]) {
            $items[] = $this->createMedia(2400 + $idx, $day->add(new DateInterval('PT' . ($idx * 600) . 'S')), $lat, $lon);
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/transit-' . $id . '.jpg',
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setGpsLat($lat);
        $media->setGpsLon($lon);

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
