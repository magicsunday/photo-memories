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
use MagicSunday\Memories\Clusterer\NightlifeEventClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class NightlifeEventClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersNightSessionsWithCompactGpsSpread(): void
    {
        $strategy = new NightlifeEventClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            timeGapSeconds: 3 * 3600,
            radiusMeters: 400.0,
            minItemsPerRun: 5,
        );

        $start = new DateTimeImmutable('2024-03-15 20:30:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; ++$i) {
            $media[] = $this->createMedia(
                610 + $i,
                $start->add(new DateInterval('PT' . ($i * 45) . 'M')),
                52.5205 + ($i * 0.0002),
                13.4049 + ($i * 0.0002),
            );
        }

        $clusters = $strategy->cluster($media);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('nightlife_event', $cluster->getAlgorithm());
        self::assertSame(range(610, 614), $cluster->getMembers());

        $timeRange = $cluster->getParams()['time_range'];
        self::assertSame($media[0]->getTakenAt()?->getTimestamp(), $timeRange['from']);
        self::assertSame($media[4]->getTakenAt()?->getTimestamp(), $timeRange['to']);
    }

    #[Test]
    public function rejectsRunsExceedingSpatialRadius(): void
    {
        $strategy = new NightlifeEventClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            timeGapSeconds: 3 * 3600,
            radiusMeters: 50.0,
            minItemsPerRun: 5,
        );

        $start = new DateTimeImmutable('2024-03-16 22:00:00', new DateTimeZone('UTC'));
        $media = [];
        for ($i = 0; $i < 5; ++$i) {
            $media[] = $this->createMedia(
                710 + $i,
                $start->add(new DateInterval('PT' . ($i * 30) . 'M')),
                52.50 + ($i * 0.01),
                13.40 + ($i * 0.01),
            );
        }

        self::assertSame([], $strategy->cluster($media));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, float $lat, float $lon): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('nightlife-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
        );
    }
}
