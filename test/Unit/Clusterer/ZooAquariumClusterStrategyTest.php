<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ZooAquariumClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ZooAquariumClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersDaytimeZooVisits(): void
    {
        $strategy = new ZooAquariumClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 1800,
            radiusMeters: 350.0,
            minItemsPerRun: 5,
            minHour: 9,
            maxHour: 19,
        );

        $start = new DateTimeImmutable('2023-08-12 09:30:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; ++$i) {
            $media[] = $this->createMedia(
                1000 + $i,
                $start->modify('+' . ($i * 20) . ' minutes'),
                sprintf('tierpark-%d.jpg', $i),
                51.0 + $i * 0.0002,
                7.0 + $i * 0.0002,
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        self::assertSame('zoo_aquarium', $clusters[0]->getAlgorithm());
        self::assertSame(range(1000, 1004), $clusters[0]->getMembers());
    }

    #[Test]
    public function rejectsSessionsOutsideOpeningHours(): void
    {
        $strategy = new ZooAquariumClusterStrategy();

        $items = [];
        for ($i = 0; $i < 5; ++$i) {
            $items[] = $this->createMedia(
                1100 + $i,
                new DateTimeImmutable('2023-08-12 22:00:00', new DateTimeZone('UTC')),
                sprintf('tierpark-%d.jpg', $i),
                51.0,
                7.0,
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
        );
    }
}
