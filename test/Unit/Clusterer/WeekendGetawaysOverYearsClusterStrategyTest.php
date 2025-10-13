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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class WeekendGetawaysOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    #[DataProvider('provideNightLengths')]
    public function aggregatesWeekendTripsAcrossYearsForNightCount(int $nightCount): void
    {
        $strategy = new WeekendGetawaysOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minNights: 1,
            maxNights: 3,
            minItemsPerDay: 4,
            minYears: 3,
            minItemsTotal: 24,
        );

        $years = [2020, 2021, 2022];
        $items = $this->createTripAcrossYears($years, $nightCount + 1, '06-05');

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('weekend_getaways_over_years', $cluster->getAlgorithm());
        self::assertSame($years, $cluster->getParams()['years']);

        $expectedMembers = count($years) * ($nightCount + 1) * 4;
        self::assertCount($expectedMembers, $cluster->getMembers());
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

        $items = $this->createTripAcrossYears([2021, 2022], 3, '07-09');

        self::assertSame([], $strategy->cluster($items));
    }

    #[Test]
    public function featureDrivenWeekendDetectionMatchesFallback(): void
    {
        $strategy = new WeekendGetawaysOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minNights: 1,
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

    #[Test]
    public function rejectsRunsWithoutCoreMetadata(): void
    {
        $strategy = new WeekendGetawaysOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minNights: 1,
            maxNights: 3,
            minItemsPerDay: 4,
            minYears: 3,
            minItemsTotal: 24,
        );

        $items = $this->createTripAcrossYears([2020, 2021, 2022], 3, '06-05');

        foreach ($items as $media) {
            $this->applyRunMetadata($media, null, withCoreTag: false, withCoreDayContext: false);
        }

        self::assertSame([], $strategy->cluster($items));
    }

    #[Test]
    public function acceptsRunsWithDayContextCoreWhenTagsMissing(): void
    {
        $strategy = new WeekendGetawaysOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minNights: 1,
            maxNights: 3,
            minItemsPerDay: 4,
            minYears: 3,
            minItemsTotal: 24,
        );

        $years = [2020, 2021, 2022];
        $items = $this->createTripAcrossYears($years, 3, '06-05');

        foreach ($items as $media) {
            $this->applyRunMetadata($media, null, withCoreTag: false, withCoreDayContext: true);
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame($years, $cluster->getParams()['years']);
    }

    #[Test]
    public function rejectsRunsWithoutDistanceSamples(): void
    {
        $strategy = new WeekendGetawaysOverYearsClusterStrategy(
            timezone: 'Europe/Berlin',
            minNights: 1,
            maxNights: 3,
            minItemsPerDay: 4,
            minYears: 3,
            minItemsTotal: 24,
        );

        $items = $this->createTripAcrossYears([2020, 2021, 2022], 3, '06-05');

        foreach ($items as $media) {
            $this->applyRunMetadata($media, null, withCoreTag: true, withCoreDayContext: false, withDistance: false);
        }

        self::assertSame([], $strategy->cluster($items));
    }

    public static function provideNightLengths(): iterable
    {
        yield 'one-night-run' => [1];
        yield 'two-night-run' => [2];
        yield 'three-night-run' => [3];
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

    private function applyRunMetadata(
        Media $media,
        ?bool $isWeekend,
        bool $withCoreTag = true,
        bool $withCoreDayContext = false,
        bool $withDistance = true,
    ): void {
        $features = [
            'day_summary' => [
                'base_away'              => true,
                'distance_from_home_km'  => $withDistance ? 150.0 : null,
                'max_speed_kmh'          => $withDistance ? 110.0 : null,
                'avg_speed_kmh'          => $withDistance ? 80.0 : null,
                'category'               => $isWeekend === true ? 'weekend' : null,
            ],
        ];

        $vacation = [];

        if ($withCoreTag) {
            $vacation['core_tag']   = 'core';
            $vacation['core_score'] = 0.72;
        }

        if ($withCoreDayContext) {
            $takenAt = $media->getTakenAt();
            if ($takenAt instanceof DateTimeImmutable) {
                $dayKey = $takenAt->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
                $vacation['day_context'] = [
                    $dayKey => [
                        'score'    => 0.8,
                        'category' => 'core',
                        'duration' => null,
                        'metrics'  => [],
                    ],
                ];
            }
        }

        if ($vacation !== []) {
            $features['vacation'] = $vacation;
        }

        if ($isWeekend !== null) {
            $features['calendar'] = ['isWeekend' => $isWeekend];
        }

        $media->setFeatures($features);
        $media->setDistanceKmFromHome($withDistance ? 150.0 : null);
    }
}
