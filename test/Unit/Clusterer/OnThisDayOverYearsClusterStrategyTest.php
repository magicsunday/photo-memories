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
use MagicSunday\Memories\Clusterer\OnThisDayOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function count;

final class OnThisDayOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function collectsItemsAcrossYearsNearAnchorDay(): void
    {
        $strategy = new OnThisDayOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            windowDays: 1,
            minYears: 3,
            minItemsTotal: 5,
        );

        $this->runWithStableClock(
            new DateTimeZone('Europe/Berlin'),
            'Y-m-d',
            function (DateTimeImmutable $anchor, callable $isStable) use ($strategy): bool {
                $month = (int) $anchor->format('n');
                $day   = (int) $anchor->format('j');

                $mediaItems = [];
                $id         = 1;
                foreach ([2019, 2020, 2021] as $year) {
                    $mediaItems[] = $this->createMedia($id++, $this->dateString($year, $month, $day, '09:00:00'));
                    $mediaItems[] = $this->createMedia($id++, $this->dateString($year, $month, $day + ($year === 2020 ? 1 : 0), '14:30:00'));
                }

                $mediaItems[] = $this->createMedia($id++, $this->dateString(2022, $month, $day + 5, '10:00:00'));

                $clusters = $strategy->cluster($mediaItems);

                if (!$isStable()) {
                    return false;
                }

                self::assertCount(1, $clusters);
                $cluster = $clusters[0];

                self::assertSame('on_this_day_over_years', $cluster->getAlgorithm());
                self::assertSame([1, 2, 3, 4, 5, 6], $cluster->getMembers());
                self::assertGreaterThanOrEqual(3, count($cluster->getParams()['years']));

                return true;
            }
        );
    }

    #[Test]
    public function requiresMinimumYearsAndItems(): void
    {
        $strategy = new OnThisDayOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            windowDays: 0,
            minYears: 4,
            minItemsTotal: 5,
        );

        $this->runWithStableClock(
            new DateTimeZone('Europe/Berlin'),
            'Y-m-d',
            function (DateTimeImmutable $anchor, callable $isStable) use ($strategy): bool {
                $month = (int) $anchor->format('n');
                $day   = (int) $anchor->format('j');

                $mediaItems = [
                    $this->createMedia(51, $this->dateString(2019, $month, $day, '09:00:00')),
                    $this->createMedia(52, $this->dateString(2020, $month, $day, '10:00:00')),
                    $this->createMedia(53, $this->dateString(2021, $month, $day, '11:00:00')),
                ];

                if (!$isStable()) {
                    return false;
                }

                self::assertSame([], $strategy->cluster($mediaItems));

                return true;
            }
        );
    }

    private function dateString(int $year, int $month, int $day, string $time): string
    {
        $base = new DateTimeImmutable(\sprintf('%04d-%02d-01 %s', $year, $month, $time));

        return $base->setDate($year, $month, $day)->format('Y-m-d H:i:s');
    }

    private function createMedia(int $id, string $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('on-this-day-%d.jpg', $id),
            takenAt: $takenAt,
        );
    }
}
