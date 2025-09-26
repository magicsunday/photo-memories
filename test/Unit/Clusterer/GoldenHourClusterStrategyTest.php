<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\GoldenHourClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class GoldenHourClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersGoldenHourSequence(): void
    {
        $strategy = new GoldenHourClusterStrategy(
            timezone: 'Europe/Berlin',
            morningHours: [6, 7, 8],
            eveningHours: [18, 19, 20],
            sessionGapSeconds: 1200,
            minItemsPerRun: 5,
        );

        $base = new DateTimeImmutable('2024-08-10 18:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->createMedia(
                2700 + $i,
                $base->add(new DateInterval('PT' . ($i * 600) . 'S')),
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        self::assertSame('golden_hour', $clusters[0]->getAlgorithm());
        self::assertSame(range(2700, 2704), $clusters[0]->getMembers());
    }

    #[Test]
    public function ignoresPhotosOutsideGoldenHours(): void
    {
        $strategy = new GoldenHourClusterStrategy();

        $base = new DateTimeImmutable('2024-08-10 13:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->createMedia(
                2800 + $i,
                $base->add(new DateInterval('PT' . ($i * 600) . 'S')),
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: "golden-{$id}.jpg",
            takenAt: $takenAt,
            lat: 48.5,
            lon: 9.0,
        );
    }

}
