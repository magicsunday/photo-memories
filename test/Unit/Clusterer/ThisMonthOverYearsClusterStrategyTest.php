<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ThisMonthOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class ThisMonthOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function aggregatesCurrentMonthAcrossYears(): void
    {
        $strategy = new ThisMonthOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minYears: 3,
            minItemsTotal: 6,
            minDistinctDays: 4,
        );

        $this->runWithStableClock(
            new DateTimeZone('Europe/Berlin'),
            'Y-m',
            function (DateTimeImmutable $anchor, callable $isStable) use ($strategy): bool {
                $month = (int) $anchor->format('n');

                $nextMonth = $month % 12 + 1;
                $noiseYear = $nextMonth === 1 ? 2022 : 2021;

                $mediaItems = [
                    $this->createMedia(1, $anchor->setDate(2019, $month, 1)->setTime(8, 0)),
                    $this->createMedia(2, $anchor->setDate(2019, $month, 5)->setTime(9, 0)),
                    $this->createMedia(3, $anchor->setDate(2020, $month, 2)->setTime(10, 0)),
                    $this->createMedia(4, $anchor->setDate(2020, $month, 9)->setTime(11, 0)),
                    $this->createMedia(5, $anchor->setDate(2021, $month, 3)->setTime(12, 0)),
                    $this->createMedia(6, $anchor->setDate(2021, $month, 12)->setTime(13, 0)),
                    $this->createMedia(7, $anchor->setDate($noiseYear, $nextMonth, 1)->setTime(7, 0)),
                ];

                $clusters = $strategy->cluster($mediaItems);

                if (!$isStable()) {
                    return false;
                }

                self::assertCount(1, $clusters);
                $cluster = $clusters[0];

                self::assertSame('this_month_over_years', $cluster->getAlgorithm());
                self::assertSame([1, 2, 3, 4, 5, 6], $cluster->getMembers());
                self::assertSame($month, $cluster->getParams()['month']);

                return true;
            }
        );
    }

    #[Test]
    public function checksDistinctDaysRequirement(): void
    {
        $strategy = new ThisMonthOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minYears: 2,
            minItemsTotal: 4,
            minDistinctDays: 5,
        );

        $this->runWithStableClock(
            new DateTimeZone('Europe/Berlin'),
            'Y-m',
            function (DateTimeImmutable $anchor, callable $isStable) use ($strategy): bool {
                $month = (int) $anchor->format('n');

                $mediaItems = [
                    $this->createMedia(21, $anchor->setDate(2019, $month, 1)->setTime(8, 0)),
                    $this->createMedia(22, $anchor->setDate(2020, $month, 1)->setTime(9, 0)),
                    $this->createMedia(23, $anchor->setDate(2020, $month, 2)->setTime(10, 0)),
                    $this->createMedia(24, $anchor->setDate(2020, $month, 3)->setTime(11, 0)),
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
            filename: sprintf('this-month-%d.jpg', $id),
            takenAt: $takenAt->setTimezone(new DateTimeZone('UTC')),
        );
    }

}
