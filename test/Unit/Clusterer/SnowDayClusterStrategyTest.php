<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\SnowDayClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class SnowDayClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersWinterSessionsWithSnowKeywords(): void
    {
        $strategy = new SnowDayClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 5400,
            minItemsPerRun: 6,
        );

        $start = new DateTimeImmutable('2024-01-12 09:00:00', new DateTimeZone('UTC'));
        $media = [];
        $keywords = ['snow', 'ski', 'piste', 'snowboard', 'eiszapfen', 'schnee'];
        foreach ($keywords as $index => $keyword) {
            $media[] = $this->createMedia(
                1000 + $index,
                $start->add(new DateInterval('PT' . ($index * 25) . 'M')),
                47.0 + ($index * 0.001),
                11.0 + ($index * 0.001),
                $keyword . '-moment.jpg',
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('snow_day', $cluster->getAlgorithm());
        self::assertSame(range(1000, 1005), $cluster->getMembers());
    }

    #[Test]
    public function ignoresOutOfSeasonOrNonSnowSessions(): void
    {
        $strategy = new SnowDayClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 5400,
            minItemsPerRun: 4,
        );

        $start = new DateTimeImmutable('2024-04-01 09:00:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 4; $i++) {
            $media[] = $this->createMedia(
                1100 + $i,
                $start->add(new DateInterval('PT' . ($i * 30) . 'M')),
                47.5 + ($i * 0.001),
                11.5 + ($i * 0.001),
                sprintf('mountain-hike-%d.jpg', $i),
            );
        }

        self::assertSame([], $strategy->cluster($media));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon, string $filename): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: $filename,
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            size: 1024,
        );
    }

}
