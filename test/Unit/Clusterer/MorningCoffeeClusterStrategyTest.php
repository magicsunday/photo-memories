<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\MorningCoffeeClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class MorningCoffeeClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersCompactMorningCafeSession(): void
    {
        $strategy = new MorningCoffeeClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 900,
            radiusMeters: 150.0,
            minItemsPerRun: 3,
            minHour: 7,
            maxHour: 10,
        );

        $base = new DateTimeImmutable('2023-06-10 07:30:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 3; $i++) {
            $media[] = $this->createMedia(
                1500 + $i,
                $base->modify('+' . ($i * 10) . ' minutes'),
                sprintf('coffee-bar-%d.jpg', $i),
                48.208 + $i * 0.0005,
                16.372 + $i * 0.0005,
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        self::assertSame('morning_coffee', $clusters[0]->getAlgorithm());
        self::assertSame([1500, 1501, 1502], $clusters[0]->getMembers());
    }

    #[Test]
    public function rejectsEventsOutsideMorningWindow(): void
    {
        $strategy = new MorningCoffeeClusterStrategy();

        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = $this->createMedia(
                1600 + $i,
                new DateTimeImmutable('2023-06-10 13:00:00', new DateTimeZone('UTC')),
                sprintf('coffee-bar-%d.jpg', $i),
                48.21,
                16.37,
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, string $filename, float $lat, float $lon): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: $filename,
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            size: 256,
        );
    }

}
