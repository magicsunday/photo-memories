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
use MagicSunday\Memories\Clusterer\NewYearEveClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class NewYearEveClusterStrategyTest extends TestCase
{
    #[Test]
    public function clustersNewYearEveWindowPerYear(): void
    {
        $strategy = new NewYearEveClusterStrategy(
            localTimeHelper: new LocalTimeHelper('Europe/Berlin'),
            startHour: 20,
            endHour: 2,
            minItemsPerYear: 6,
        );

        $start = new DateTimeImmutable('2023-12-31 20:00:00', new DateTimeZone('Europe/Berlin'));
        $items = [];
        for ($i = 0; $i < 6; ++$i) {
            $items[] = $this->createMedia(
                2100 + $i,
                $start->add(new DateInterval('PT' . ($i * 30) . 'M')),
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('new_year_eve', $cluster->getAlgorithm());
        self::assertSame(2023, $cluster->getParams()['year']);
        self::assertSame(range(2100, 2105), $cluster->getMembers());
    }

    #[Test]
    public function ignoresPhotosOutsidePartyWindow(): void
    {
        $strategy = new NewYearEveClusterStrategy(localTimeHelper: new LocalTimeHelper('Europe/Berlin'));

        $items = [];
        for ($i = 0; $i < 6; ++$i) {
            $items[] = $this->createMedia(
                2200 + $i,
                new DateTimeImmutable('2023-12-31 15:00:00', new DateTimeZone('UTC')),
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('nye-%d.jpg', $id),
            takenAt: $takenAt,
            lat: 52.5,
            lon: 13.4,
            size: 2048,
        );
    }
}
