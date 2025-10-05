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
use MagicSunday\Memories\Clusterer\PanoramaOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PanoramaOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function aggregatesPanoramasAcrossYears(): void
    {
        $strategy = new PanoramaOverYearsClusterStrategy(
            minAspect: 2.4,
            minItemsPerYear: 3,
            minYears: 3,
            minItemsTotal: 15,
        );

        $items = [];
        foreach ([2018, 2019, 2020] as $year) {
            $day = new DateTimeImmutable(sprintf('%d-09-01 12:00:00', $year), new DateTimeZone('UTC'));
            for ($i = 0; $i < 5; ++$i) {
                $items[] = $this->createPanorama(
                    ($year * 100) + $i,
                    $day->add(new DateInterval('PT' . ($i * 600) . 'S')),
                );
            }
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('panorama_over_years', $cluster->getAlgorithm());
        self::assertSame([2018, 2019, 2020], $cluster->getParams()['years']);
        self::assertCount(15, $cluster->getMembers());
    }

    #[Test]
    public function enforcesMinimumYearCoverage(): void
    {
        $strategy = new PanoramaOverYearsClusterStrategy();

        $items = [];
        foreach ([2021, 2022] as $year) {
            $day = new DateTimeImmutable(sprintf('%d-09-01 12:00:00', $year), new DateTimeZone('UTC'));
            for ($i = 0; $i < 5; ++$i) {
                $items[] = $this->createPanorama(
                    ($year * 1000) + $i,
                    $day->add(new DateInterval('PT' . ($i * 600) . 'S')),
                );
            }
        }

        self::assertSame([], $strategy->cluster($items));
    }

    #[Test]
    public function acceptsFlaggedPanoramasAcrossYears(): void
    {
        $strategy = new PanoramaOverYearsClusterStrategy(
            minAspect: 2.4,
            minItemsPerYear: 3,
            minYears: 3,
            minItemsTotal: 9,
        );

        $items = [];
        foreach ([2017, 2018, 2019] as $year) {
            $day = new DateTimeImmutable(sprintf('%d-05-01 12:00:00', $year), new DateTimeZone('UTC'));
            for ($i = 0; $i < 3; ++$i) {
                $items[] = $this->createFlaggedPanorama(
                    ($year * 100) + $i,
                    $day->add(new DateInterval('PT' . ($i * 600) . 'S')),
                    true,
                    2200,
                    1600,
                );
            }
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        self::assertCount(9, $clusters[0]->getMembers());
    }

    #[Test]
    public function rejectsPhotosMarkedAsNonPanoramaDespiteWideAspect(): void
    {
        $strategy = new PanoramaOverYearsClusterStrategy(
            minAspect: 2.4,
            minItemsPerYear: 3,
            minYears: 3,
            minItemsTotal: 9,
        );

        $items = [];
        foreach ([2015, 2016, 2017] as $year) {
            $day = new DateTimeImmutable(sprintf('%d-07-01 12:00:00', $year), new DateTimeZone('UTC'));
            for ($i = 0; $i < 3; ++$i) {
                $items[] = $this->createFlaggedPanorama(
                    ($year * 1000) + $i,
                    $day->add(new DateInterval('PT' . ($i * 600) . 'S')),
                    false,
                    4800,
                    1500,
                );
            }
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createPanorama(int $id, DateTimeImmutable $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('pano-%d.jpg', $id),
            takenAt: $takenAt,
            lat: 46.0,
            lon: 11.0,
            configure: static function (Media $media): void {
                $media->setWidth(4800);
                $media->setHeight(1800);
            },
            size: 2048,
        );
    }

    private function createFlaggedPanorama(
        int $id,
        DateTimeImmutable $takenAt,
        bool $flag,
        int $width,
        int $height,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('flagged-%d.jpg', $id),
            takenAt: $takenAt,
            configure: static function (Media $media) use ($flag, $width, $height): void {
                $media->setWidth($width);
                $media->setHeight($height);
                $media->setIsPanorama($flag);
            },
            size: 2048,
        );
    }
}
