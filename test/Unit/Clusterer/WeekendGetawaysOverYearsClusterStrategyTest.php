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
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\WeekendGetawaysOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

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
            for ($dayOffset = 0; $dayOffset < 3; ++$dayOffset) {
                $day = $friday->add(new DateInterval('P' . $dayOffset . 'D'));
                for ($i = 0; $i < 4; ++$i) {
                    $items[] = $this->createMedia(
                        ($year * 100) + ($dayOffset * 10) + $i,
                        $day->add(new DateInterval('PT' . ($i * 900) . 'S')),
                        ['isWeekend' => (int) $day->format('N') >= 6],
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
            for ($dayOffset = 0; $dayOffset < 3; ++$dayOffset) {
                $day = $friday->add(new DateInterval('P' . $dayOffset . 'D'));
                for ($i = 0; $i < 4; ++$i) {
                    $items[] = $this->createMedia(
                        ($year * 1000) + ($dayOffset * 10) + $i,
                        $day->add(new DateInterval('PT' . ($i * 900) . 'S')),
                        ['isWeekend' => (int) $day->format('N') >= 6],
                    );
                }
            }
        }

        self::assertSame([], $strategy->cluster($items));
    }

    #[Test]
    public function featureBasedAndFallbackWeekendDetectionMatch(): void
    {
        $strategy = new WeekendGetawaysOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minNights: 1,
            maxNights: 3,
            minItemsPerDay: 4,
            minYears: 3,
            minItemsTotal: 24,
        );

        $dataset = [];

        foreach ([2020, 2021, 2022] as $year) {
            $friday = new DateTimeImmutable(sprintf('%d-09-10 16:00:00', $year), new DateTimeZone('UTC'));
            for ($dayOffset = 0; $dayOffset < 3; ++$dayOffset) {
                $day = $friday->add(new DateInterval('P' . $dayOffset . 'D'));
                $isWeekend = (int) $day->format('N') >= 6;

                for ($i = 0; $i < 4; ++$i) {
                    $dataset[] = [
                        'id'        => ($year * 10_000) + ($dayOffset * 100) + $i,
                        'takenAt'   => $day->add(new DateInterval('PT' . ($i * 900) . 'S')),
                        'isWeekend' => $isWeekend,
                    ];
                }
            }
        }

        $withFeatures = [];
        $fallbackOnly = [];

        foreach ($dataset as $entry) {
            $withFeatures[] = $this->createMedia(
                $entry['id'],
                $entry['takenAt'],
                ['isWeekend' => $entry['isWeekend']],
            );

            $fallbackOnly[] = $this->createMedia(
                $entry['id'],
                $entry['takenAt'],
            );
        }

        $clustersWith = $strategy->cluster($withFeatures);
        $clustersWithout = $strategy->cluster($fallbackOnly);

        self::assertSame(
            $this->normaliseClusters($clustersWithout),
            $this->normaliseClusters($clustersWith),
        );
    }

    /**
     * @param list<ClusterDraft> $clusters
     *
     * @return list<array{algorithm: string, params: array, members: list<int>}> 
     */
    private function normaliseClusters(array $clusters): array
    {
        return array_map(
            static fn (ClusterDraft $cluster): array => [
                'algorithm' => $cluster->getAlgorithm(),
                'params'    => $cluster->getParams(),
                'members'   => $cluster->getMembers(),
            ],
            $clusters,
        );
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt, ?array $features = null): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('weekend-getaway-%d.jpg', $id),
            takenAt: $takenAt,
            lat: 47.0,
            lon: 11.0,
            size: 2048,
            configure: $features !== null
                ? static function (Media $media) use ($features): void {
                    $media->setFeatures($features);
                }
                : null,
        );
    }
}
