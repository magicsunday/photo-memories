<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\SportsEventClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class SportsEventClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersWeekendSessionsWithSportKeywords(): void
    {
        $strategy = new SportsEventClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 3600,
            radiusMeters: 600.0,
            minItemsPerRun: 5,
            preferWeekend: true,
        );

        $mediaItems = [];
        for ($i = 0; $i < 5; $i++) {
            $mediaItems[] = $this->createMedia(
                100 + $i,
                new DateTimeImmutable('2024-03-16 ' . (18 + $i) . ':00:00', new DateTimeZone('UTC')),
                "matchday-{$i}.jpg",
                52.51 + $i * 0.0003,
                13.4 + $i * 0.0003,
            );
        }

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('sports_event', $cluster->getAlgorithm());
        self::assertSame([100, 101, 102, 103, 104], $cluster->getMembers());
        self::assertArrayHasKey('time_range', $cluster->getParams());
    }

    #[Test]
    public function skipsWeekdayOrSparseSessions(): void
    {
        $strategy = new SportsEventClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 3600,
            radiusMeters: 600.0,
            minItemsPerRun: 5,
            preferWeekend: true,
        );

        $weekdayItems = [];
        for ($i = 0; $i < 5; $i++) {
            $weekdayItems[] = $this->createMedia(
                200 + $i,
                new DateTimeImmutable('2024-03-13 ' . (18 + $i) . ':00:00', new DateTimeZone('UTC')),
                "matchday-{$i}.jpg",
                52.5 + $i * 0.01,
                13.3 + $i * 0.01,
            );
        }

        self::assertSame([], $strategy->cluster($weekdayItems));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, string $filename, float $lat, float $lon): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: $filename,
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
        );
    }

}
