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
use MagicSunday\Memories\Clusterer\DiningOutClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DiningOutClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersEveningDiningSessionsWithKeywords(): void
    {
        $strategy = new DiningOutClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 2 * 3600,
            radiusMeters: 200.0,
            minItemsPerRun: 4,
            minHour: 17,
            maxHour: 23,
        );

        $start    = new DateTimeImmutable('2024-02-10 17:30:00', new DateTimeZone('UTC'));
        $media    = [];
        $keywords = ['restaurant', 'dinner', 'wine', 'tapas'];
        foreach ($keywords as $index => $keyword) {
            $media[] = $this->createMedia(
                820 + $index,
                $start->add(new DateInterval('PT' . ($index * 25) . 'M')),
                40.7128 + ($index * 0.0002),
                -74.0060 + ($index * 0.0002),
                __DIR__ . sprintf('/fixtures/%s-shot.jpg', $keyword),
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('dining_out', $cluster->getAlgorithm());
        self::assertSame(range(820, 823), $cluster->getMembers());
    }

    #[Test]
    public function splitsSessionsWhenGapExceedsThreshold(): void
    {
        $strategy = new DiningOutClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 1800,
            radiusMeters: 250.0,
            minItemsPerRun: 3,
            minHour: 16,
            maxHour: 22,
        );

        $start = new DateTimeImmutable('2024-02-11 18:00:00', new DateTimeZone('UTC'));
        $media = [
            $this->createMedia(900, $start, 34.0522, -118.2437, __DIR__ . '/fixtures/restaurant-appetizer.jpg'),
            $this->createMedia(901, $start->add(new DateInterval('PT20M')), 34.0523, -118.2436, __DIR__ . '/fixtures/restaurant-main.jpg'),
            $this->createMedia(902, $start->add(new DateInterval('PT40M')), 34.0524, -118.2435, __DIR__ . '/fixtures/restaurant-dessert.jpg'),
            $this->createMedia(903, $start->add(new DateInterval('PT120M')), 34.0525, -118.2434, __DIR__ . '/fixtures/restaurant-nightcap.jpg'),
        ];

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        self::assertSame([900, 901, 902], $clusters[0]->getMembers());
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon, string $path): Media
    {
        return $this->makeMedia(
            id: $id,
            path: $path,
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
        );
    }
}
