<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\HikeAdventureClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class HikeAdventureClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersHikeWhenDistanceMet(): void
    {
        $strategy = new HikeAdventureClusterStrategy(
            sessionGapSeconds: 1800,
            minDistanceKm: 5.0,
            minItemsPerRun: 6,
            minItemsPerRunNoGps: 10,
        );

        $start = new DateTimeImmutable('2023-09-10 08:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 6; ++$i) {
            $items[] = $this->createMedia(
                3100 + $i,
                $start->add(new DateInterval('PT' . ($i * 900) . 'S')),
                47.0 + $i * 0.05,
                10.0 + $i * 0.05,
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        self::assertSame('hike_adventure', $clusters[0]->getAlgorithm());
        self::assertSame(range(3100, 3105), $clusters[0]->getMembers());
    }

    #[Test]
    public function requiresSufficientGpsDistance(): void
    {
        $strategy = new HikeAdventureClusterStrategy(
            sessionGapSeconds: 1800,
            minDistanceKm: 8.0,
            minItemsPerRun: 6,
            minItemsPerRunNoGps: 10,
        );

        $start = new DateTimeImmutable('2023-09-11 08:00:00', new DateTimeZone('UTC'));
        $items = [];
        for ($i = 0; $i < 6; ++$i) {
            $items[] = $this->createMedia(
                3200 + $i,
                $start->add(new DateInterval('PT' . ($i * 900) . 'S')),
                47.0 + $i * 0.005,
                10.0 + $i * 0.005,
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('wanderung-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
        );
    }
}
