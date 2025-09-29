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
use MagicSunday\Memories\Clusterer\FestivalSummerClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FestivalSummerClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersSummerFestivalSessions(): void
    {
        $strategy = new FestivalSummerClusterStrategy(
            timezone: 'Europe/Berlin',
            sessionGapSeconds: 1800,
            radiusMeters: 500.0,
            minItemsPerRun: 8,
            startMonth: 6,
            endMonth: 9,
            afternoonStartHour: 14,
            lateNightCutoffHour: 2,
        );

        $start = new DateTimeImmutable('2023-07-15 14:00:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 8; ++$i) {
            $media[] = $this->createMedia(
                800 + $i,
                $start->modify('+' . ($i * 15) . ' minutes'),
                sprintf('open-air-stage-%d.jpg', $i),
                50.0 + $i * 0.0003,
                8.0 + $i * 0.0003,
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        self::assertSame('festival_summer', $clusters[0]->getAlgorithm());
        self::assertSame(range(800, 807), $clusters[0]->getMembers());
    }

    #[Test]
    public function ignoresEventsOutsideSeason(): void
    {
        $strategy = new FestivalSummerClusterStrategy();

        $items = [];
        for ($i = 0; $i < 8; ++$i) {
            $items[] = $this->createMedia(
                900 + $i,
                new DateTimeImmutable('2023-11-01 18:00:00', new DateTimeZone('UTC')),
                sprintf('open-air-stage-%d.jpg', $i),
                50.0,
                8.0,
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
            size: 4096,
        );
    }
}
