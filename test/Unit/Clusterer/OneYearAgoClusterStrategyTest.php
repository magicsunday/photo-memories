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
use MagicSunday\Memories\Clusterer\OneYearAgoClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OneYearAgoClusterStrategyTest extends TestCase
{
    #[Test]
    public function gathersItemsWithinWindowAroundLastYear(): void
    {
        $strategy = new OneYearAgoClusterStrategy(
            timezone: 'Europe/Berlin',
            windowDays: 2,
            minItemsTotal: 4,
        );

        $this->runWithStableClock(
            new DateTimeZone('Europe/Berlin'),
            'Y-m-d',
            function (DateTimeImmutable $anchor, callable $isStable) use ($strategy): bool {
                $anchorBase = $anchor->sub(new DateInterval('P1Y'));

                $mediaItems = [
                    $this->createMedia(1, $anchorBase->modify('-1 day')),
                    $this->createMedia(2, $anchorBase),
                    $this->createMedia(3, $anchorBase->modify('+1 day')),
                    $this->createMedia(4, $anchorBase->modify('+2 days')),
                    $this->createMedia(5, $anchorBase->modify('+5 days')),
                ];

                $clusters = $strategy->cluster($mediaItems);

                if (!$isStable()) {
                    return false;
                }

                self::assertCount(1, $clusters);
                $cluster = $clusters[0];

                self::assertSame('one_year_ago', $cluster->getAlgorithm());
                self::assertSame([1, 2, 3, 4], $cluster->getMembers());
                self::assertArrayHasKey('time_range', $cluster->getParams());

                return true;
            }
        );
    }

    #[Test]
    public function enforcesMinimumItemCount(): void
    {
        $strategy = new OneYearAgoClusterStrategy(
            timezone: 'Europe/Berlin',
            windowDays: 1,
            minItemsTotal: 3,
        );

        $this->runWithStableClock(
            new DateTimeZone('Europe/Berlin'),
            'Y-m-d',
            function (DateTimeImmutable $anchor, callable $isStable) use ($strategy): bool {
                $anchorBase = $anchor->sub(new DateInterval('P1Y'));

                $mediaItems = [
                    $this->createMedia(11, $anchorBase),
                    $this->createMedia(12, $anchorBase->modify('+1 day')),
                ];

                if (!$isStable()) {
                    return false;
                }

                self::assertSame([], $strategy->cluster($mediaItems));

                return true;
            }
        );
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('one-year-ago-%d.jpg', $id),
            takenAt: $takenAt->setTimezone(new DateTimeZone('UTC')),
        );
    }
}
