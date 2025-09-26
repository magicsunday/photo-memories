<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\WeekendGetawaysOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeekendGetawaysOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function aggregatesWeekendTripsAcrossYears(): void
    {
        $strategy = new WeekendGetawaysOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minNights: 1,
            maxNights: 3,
            minItemsPerDay: 4,
            minYears: 3,
            minItemsTotal: 24,
        );

        $items = [];
        foreach ([2020, 2021, 2022] as $year) {
            $friday = new DateTimeImmutable(sprintf('%d-06-05 16:00:00', $year), new DateTimeZone('UTC')); // Friday
            for ($dayOffset = 0; $dayOffset < 3; $dayOffset++) {
                $day = $friday->add(new DateInterval('P' . $dayOffset . 'D'));
                for ($i = 0; $i < 4; $i++) {
                    $items[] = $this->createMedia(
                        ($year * 100) + ($dayOffset * 10) + $i,
                        $day->add(new DateInterval('PT' . ($i * 900) . 'S')),
                    );
                }
            }
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('weekend_getaways_over_years', $cluster->getAlgorithm());
        self::assertSame([2020, 2021, 2022], $cluster->getParams()['years']);
        self::assertCount(36, $cluster->getMembers());
    }

    #[Test]
    public function enforcesMinimumYearCount(): void
    {
        $strategy = new WeekendGetawaysOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minNights: 1,
            maxNights: 3,
            minItemsPerDay: 4,
            minYears: 3,
            minItemsTotal: 24,
        );

        $items = [];
        foreach ([2021, 2022] as $year) {
            $friday = new DateTimeImmutable(sprintf('%d-07-09 16:00:00', $year), new DateTimeZone('UTC'));
            for ($dayOffset = 0; $dayOffset < 3; $dayOffset++) {
                $day = $friday->add(new DateInterval('P' . $dayOffset . 'D'));
                for ($i = 0; $i < 4; $i++) {
                    $items[] = $this->createMedia(
                        ($year * 1000) + ($dayOffset * 10) + $i,
                        $day->add(new DateInterval('PT' . ($i * 900) . 'S')),
                    );
                }
            }
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/weekend-getaway-' . $id . '.jpg',
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 2048,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setGpsLat(47.0);
        $media->setGpsLon(11.0);

        return $media;
    }

    private function assignId(Media $media, int $id): void
    {
        \Closure::bind(function (Media $m, int $value): void {
            $m->id = $value;
        }, null, Media::class)($media, $id);
    }
}
