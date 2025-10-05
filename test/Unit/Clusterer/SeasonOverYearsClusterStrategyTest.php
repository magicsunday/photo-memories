<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\SeasonOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SeasonOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function mergesSeasonAcrossYears(): void
    {
        $strategy = new SeasonOverYearsClusterStrategy(
            minYears: 3,
            minItemsPerSeason: 6,
        );

        $mediaItems = [
            $this->createMedia(1, '2019-07-01 08:00:00', ['season' => 'summer']),
            $this->createMedia(2, '2019-07-05 09:00:00', ['season' => 'summer']),
            $this->createMedia(3, '2020-08-10 10:00:00', ['season' => 'summer']),
            $this->createMedia(4, '2020-08-11 11:00:00', ['season' => 'summer']),
            $this->createMedia(5, '2021-06-15 12:00:00', ['season' => 'summer']),
            $this->createMedia(6, '2021-06-18 13:00:00', ['season' => 'summer']),
            $this->createMedia(7, '2021-12-05 14:00:00', ['season' => 'winter']),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('season_over_years', $cluster->getAlgorithm());
        self::assertSame('Sommer im Laufe der Jahre', $cluster->getParams()['label']);
        self::assertSame([1, 2, 3, 4, 5, 6], $cluster->getMembers());
        self::assertContains(2021, $cluster->getParams()['years']);
    }

    #[Test]
    public function requiresMinimumYears(): void
    {
        $strategy = new SeasonOverYearsClusterStrategy(
            minYears: 4,
            minItemsPerSeason: 5,
        );

        $mediaItems = [
            $this->createMedia(11, '2019-04-01 08:00:00'),
            $this->createMedia(12, '2020-04-02 09:00:00'),
            $this->createMedia(13, '2021-04-03 10:00:00'),
            $this->createMedia(14, '2021-04-04 11:00:00'),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    #[Test]
    public function featureBasedAndFallbackAggregationMatch(): void
    {
        $strategy = new SeasonOverYearsClusterStrategy(
            minYears: 3,
            minItemsPerSeason: 6,
        );

        $dataset = [
            ['id' => 31, 'takenAt' => '2019-06-20 08:00:00', 'season' => 'summer'],
            ['id' => 32, 'takenAt' => '2019-07-05 09:00:00', 'season' => 'summer'],
            ['id' => 33, 'takenAt' => '2020-08-12 10:00:00', 'season' => 'summer'],
            ['id' => 34, 'takenAt' => '2020-08-14 12:30:00', 'season' => 'summer'],
            ['id' => 35, 'takenAt' => '2021-06-18 13:00:00', 'season' => 'summer'],
            ['id' => 36, 'takenAt' => '2021-07-01 14:00:00', 'season' => 'summer'],
        ];

        $withFeatures = [];
        $fallbackOnly = [];

        foreach ($dataset as $row) {
            $withFeatures[] = $this->createMedia($row['id'], $row['takenAt'], ['season' => $row['season']]);
            $fallbackOnly[] = $this->createMedia($row['id'], $row['takenAt']);
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

    private function createMedia(int $id, string $takenAt, ?array $features = null): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('season-over-years-%d.jpg', $id),
            takenAt: $takenAt,
            configure: $features !== null
                ? static function (Media $media) use ($features): void {
                    $media->setFeatures($features);
                }
                : null,
        );
    }
}
