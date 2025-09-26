<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\PanoramaOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\TestCase;

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
            for ($i = 0; $i < 5; $i++) {
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
            for ($i = 0; $i < 5; $i++) {
                $items[] = $this->createPanorama(
                    ($year * 1000) + $i,
                    $day->add(new DateInterval('PT' . ($i * 600) . 'S')),
                );
            }
        }

        self::assertSame([], $strategy->cluster($items));
    }

    private function createPanorama(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = new Media(
            path: __DIR__ . '/fixtures/pano-' . $id . '.jpg',
            checksum: str_pad((string) $id, 64, '0', STR_PAD_LEFT),
            size: 2048,
        );

        $this->assignId($media, $id);
        $media->setTakenAt($takenAt);
        $media->setWidth(4800);
        $media->setHeight(1800);
        $media->setGpsLat(46.0);
        $media->setGpsLon(11.0);

        return $media;
    }

}
