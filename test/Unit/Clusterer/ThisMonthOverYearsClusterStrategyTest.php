<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ThisMonthOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThisMonthOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function aggregatesCurrentMonthAcrossYears(): void
    {
        $strategy = new ThisMonthOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minYears: 3,
            minItems: 6,
            minDistinctDays: 4,
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        $month = (int) $now->format('n');

        $nextMonth = $month % 12 + 1;
        $noiseYear = $nextMonth === 1 ? 2022 : 2021;

        $mediaItems = [
            $this->createMedia(1, $now->setDate(2019, $month, 1)->setTime(8, 0)),
            $this->createMedia(2, $now->setDate(2019, $month, 5)->setTime(9, 0)),
            $this->createMedia(3, $now->setDate(2020, $month, 2)->setTime(10, 0)),
            $this->createMedia(4, $now->setDate(2020, $month, 9)->setTime(11, 0)),
            $this->createMedia(5, $now->setDate(2021, $month, 3)->setTime(12, 0)),
            $this->createMedia(6, $now->setDate(2021, $month, 12)->setTime(13, 0)),
            $this->createMedia(7, $now->setDate($noiseYear, $nextMonth, 1)->setTime(7, 0)),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('this_month_over_years', $cluster->getAlgorithm());
        self::assertSame([1, 2, 3, 4, 5, 6], $cluster->getMembers());
        self::assertSame($month, $cluster->getParams()['month']);
    }

    #[Test]
    public function checksDistinctDaysRequirement(): void
    {
        $strategy = new ThisMonthOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minYears: 2,
            minItems: 4,
            minDistinctDays: 5,
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        $month = (int) $now->format('n');

        $mediaItems = [
            $this->createMedia(21, $now->setDate(2019, $month, 1)->setTime(8, 0)),
            $this->createMedia(22, $now->setDate(2020, $month, 1)->setTime(9, 0)),
            $this->createMedia(23, $now->setDate(2020, $month, 2)->setTime(10, 0)),
            $this->createMedia(24, $now->setDate(2020, $month, 3)->setTime(11, 0)),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . "/fixtures/this-month-{$id}.jpg",
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt->setTimezone(new DateTimeZone('UTC')));

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
