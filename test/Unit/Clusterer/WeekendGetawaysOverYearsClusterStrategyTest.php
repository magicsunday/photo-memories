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
            minNights: 2,
            maxNights: 3,
            minItemsPerDay: 4,
            minYears: 3,
            minItemsTotal: 24,
        );

        $items = $this->createTripAcrossYears([2020, 2021, 2022], 3, '06-05');

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('weekend_getaways_over_years', $cluster->getAlgorithm());
        self::assertSame([2020, 2021, 2022], $cluster->getParams()['years']);
        self::assertCount(36, $cluster->getMembers());
    }

    #[Test]
    public function aggregatesExtendedWeekendTripsAcrossYears(): void
    {
        $strategy = new WeekendGetawaysOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minNights: 2,
            maxNights: 3,
            minItemsPerDay: 4,
            minYears: 3,
            minItemsTotal: 24,
        );

        $items = $this->createTripAcrossYears([2018, 2019, 2021], 4, '05-16');

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('weekend_getaways_over_years', $cluster->getAlgorithm());
        self::assertSame([2018, 2019, 2021], $cluster->getParams()['years']);
        self::assertCount(48, $cluster->getMembers());
    }

    #[Test]
    public function enforcesMinimumYearCount(): void
    {
        $strategy = new WeekendGetawaysOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minNights: 2,
            maxNights: 3,
            minItemsPerDay: 4,
            minYears: 3,
            minItemsTotal: 24,
        );

        $items = $this->createTripAcrossYears([2021, 2022], 3, '07-09');

        self::assertSame([], $strategy->cluster($items));
    }

    #[Test]
    public function featureDrivenWeekendDetectionMatchesFallback(): void
    {
        $strategy = new WeekendGetawaysOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minNights: 2,
            maxNights: 3,
            minItemsPerDay: 4,
            minYears: 2,
            minItemsTotal: 16,
        );

        $items = $this->createTripAcrossYears([2020, 2021], 3, '09-04');

        $fallbackClusters = $this->normaliseClusters($strategy->cluster($items));

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $isWeekend = ((int) $takenAt->format('N')) >= 6;
            $this->applyRunMetadata($media, $isWeekend);
        }

        $featureClusters = $this->normaliseClusters($strategy->cluster($items));

        self::assertSame($fallbackClusters, $featureClusters);
    }

    private function createMedia(int $id, DateTimeImmutable $takenAt): Media
    {
        $media = $this->makeMediaFixture(
            id: $id,
            filename: sprintf('weekend-getaway-%d.jpg', $id),
            takenAt: $takenAt,
            lat: 47.0,
            lon: 11.0,
            size: 2048,
        );

        $this->applyRunMetadata($media, null);

        return $media;
    }

    /**
     * @param list<int> $years
     *
     * @return list<Media>
     */
    private function createTripAcrossYears(array $years, int $dayCount, string $monthDay): array
    {
        $items = [];

        foreach ($years as $year) {
            $start = new DateTimeImmutable(sprintf('%d-%s 16:00:00', $year, $monthDay), new DateTimeZone('UTC'));

            for ($dayOffset = 0; $dayOffset < $dayCount; ++$dayOffset) {
                $day = $start->add(new DateInterval('P' . $dayOffset . 'D'));

                for ($i = 0; $i < 4; ++$i) {
                    $items[] = $this->createMedia(
                        ($year * 1000) + ($dayOffset * 10) + $i,
                        $day->add(new DateInterval('PT' . ($i * 900) . 'S')),
                    );
                }
            }
        }

        return $items;
    }

    /**
     * @param list<ClusterDraft> $clusters
     *
     * @return list<array{algorithm: string, params: array, centroid: array, members: list<int>}>
     */
    private function normaliseClusters(array $clusters): array
    {
        return array_map(
            static fn (ClusterDraft $cluster): array => [
                'algorithm' => $cluster->getAlgorithm(),
                'params'    => $cluster->getParams(),
                'centroid'  => $cluster->getCentroid(),
                'members'   => $cluster->getMembers(),
            ],
            $clusters,
        );
    }

    private function applyRunMetadata(Media $media, ?bool $isWeekend): void
    {
        $features = [
            'day_summary' => [
                'base_away'              => true,
                'distance_from_home_km'  => 150.0,
                'max_speed_kmh'          => 110.0,
                'avg_speed_kmh'          => 80.0,
                'category'               => $isWeekend === true ? 'weekend' : null,
            ],
            'vacation' => [
                'core_tag'   => 'core',
                'core_score' => 0.72,
            ],
        ];

        if ($isWeekend !== null) {
            $features['calendar'] = ['isWeekend' => $isWeekend];
        }

        $media->setFeatures($features);
        $media->setDistanceKmFromHome(150.0);
    }
}
