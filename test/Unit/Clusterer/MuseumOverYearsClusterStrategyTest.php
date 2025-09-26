<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\MuseumOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

final class MuseumOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function aggregatesMuseumVisitsAcrossYears(): void
    {
        $strategy = new MuseumOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 5,
            minYears: 3,
            minItemsTotal: 18,
        );

        $items = [];
        foreach ([2019, 2020, 2021] as $year) {
            $day = new DateTimeImmutable(sprintf('%d-03-10 11:00:00', $year), new DateTimeZone('UTC'));
            for ($i = 0; $i < 6; $i++) {
                $items[] = $this->createMedia(
                    ($year * 100) + $i,
                    $day->add(new DateInterval('PT' . ($i * 300) . 'S')),
                    $year,
                    $i,
                );
            }
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('museum_over_years', $cluster->getAlgorithm());
        self::assertSame([2019, 2020, 2021], $cluster->getParams()['years']);
        self::assertCount(18, $cluster->getMembers());
    }

    #[Test]
    public function enforcesMinimumYears(): void
    {
        $strategy = new MuseumOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minItemsPerDay: 5,
            minYears: 3,
            minItemsTotal: 18,
        );

        $items = [];
        foreach ([2021, 2022] as $year) {
            $day = new DateTimeImmutable(sprintf('%d-04-05 12:00:00', $year), new DateTimeZone('UTC'));
            for ($i = 0; $i < 6; $i++) {
                $items[] = $this->createMedia(
                    ($year * 1000) + $i,
                    $day->add(new DateInterval('PT' . ($i * 300) . 'S')),
                    $year,
                    $i,
                );
            }
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, int $year, int $index): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/' . sprintf('museum-%d-%d.jpg', $year, $index),
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 1024,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setGpsLat(52.0 + $index * 0.01);
        $media->setGpsLon(13.0 + $index * 0.01);

        return $media;
    }

}
