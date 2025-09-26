<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ZooAquariumOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class ZooAquariumOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function aggregatesBestZooDayPerYear(): void
    {
        $strategy = new ZooAquariumOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 5,
            minYears: 3,
            minItemsTotal: 18,
        );

        $items = [];
        foreach ([2021, 2022, 2023] as $year) {
            $day = new DateTimeImmutable(sprintf('%d-08-15 10:00:00', $year), new DateTimeZone('UTC'));
            for ($i = 0; $i < 6; $i++) {
                $items[] = $this->createMedia(
                    ($year * 100) + $i,
                    $day->add(new DateInterval('PT' . ($i * 300) . 'S')),
                    "zoo-%d-%d.jpg",
                    $year,
                    $i,
                );
            }
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('zoo_aquarium_over_years', $cluster->getAlgorithm());
        self::assertCount(18, $cluster->getMembers());
        self::assertSame([2021, 2022, 2023], $cluster->getParams()['years']);
    }

    #[Test]
    public function requiresEnoughYears(): void
    {
        $strategy = new ZooAquariumOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 5,
            minYears: 3,
            minItemsTotal: 18,
        );

        $items = [];
        foreach ([2022, 2023] as $year) {
            $day = new DateTimeImmutable(sprintf('%d-08-15 10:00:00', $year), new DateTimeZone('UTC'));
            for ($i = 0; $i < 6; $i++) {
                $items[] = $this->createMedia(
                    ($year * 1000) + $i,
                    $day->add(new DateInterval('PT' . ($i * 300) . 'S')),
                    "zoo-%d-%d.jpg",
                    $year,
                    $i,
                );
            }
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, string $pattern, int $year, int $index): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf($pattern, $year, $index),
            takenAt: $takenAt,
            lat: 50.0 + $index * 0.01,
            lon: 8.0 + $index * 0.01,
            size: 512,
        );
    }

}
