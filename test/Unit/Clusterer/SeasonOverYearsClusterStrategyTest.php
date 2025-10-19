<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\Context;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\SeasonOverYearsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

use function array_filter;
use function array_key_last;
use function array_values;
use function str_starts_with;

final class SeasonOverYearsClusterStrategyTest extends TestCase
{
    #[Test]
    public function mergesSeasonAcrossYears(): void
    {
        $strategy = new SeasonOverYearsClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minYears: 3,
            minItemsPerSeason: 6,
        );

        $mediaItems = [
            $this->createMedia(1, '2019-07-01 08:00:00'),
            $this->createMedia(2, '2019-07-05 09:00:00'),
            $this->createMedia(3, '2020-08-10 10:00:00'),
            $this->createMedia(4, '2020-08-11 11:00:00'),
            $this->createMedia(5, '2021-06-15 12:00:00'),
            $this->createMedia(6, '2021-06-18 13:00:00'),
            $this->createMedia(7, '2021-12-05 14:00:00'),
        ];

        foreach ([0, 1, 2, 3, 4, 5] as $index) {
            $this->assignTags($mediaItems[$index], [
                ['label' => 'Sommer', 'score' => 0.88 - ($index * 0.02)],
                ['label' => 'Outdoor', 'score' => 0.7],
            ], ['Sommer', 'Urlaub']);
        }

        $clusters = $strategy->draft($mediaItems, Context::fromScope($mediaItems));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('season_over_years', $cluster->getAlgorithm());
        $params = $cluster->getParams();
        self::assertSame('Sommer im Laufe der Jahre', $params['label']);
        self::assertSame([1, 2, 3, 4, 5, 6], $cluster->getMembers());
        self::assertContains(2021, $params['years']);
        self::assertArrayHasKey('scene_tags', $params);
        self::assertArrayHasKey('keywords', $params);
        $sceneTags = $params['scene_tags'];
        self::assertCount(2, $sceneTags);
        self::assertSame('Sommer', $sceneTags[0]['label']);
        self::assertEqualsWithDelta(0.88, $sceneTags[0]['score'], 0.0001);
        self::assertSame('Outdoor', $sceneTags[1]['label']);
        self::assertEqualsWithDelta(0.7, $sceneTags[1]['score'], 0.0001);
        self::assertSame(['Sommer', 'Urlaub'], $params['keywords']);
    }

    #[Test]
    public function requiresMinimumYears(): void
    {
        $strategy = new SeasonOverYearsClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minYears: 4,
            minItemsPerSeason: 5,
        );

        $mediaItems = [
            $this->createMedia(11, '2019-04-01 08:00:00'),
            $this->createMedia(12, '2020-04-02 09:00:00'),
            $this->createMedia(13, '2021-04-03 10:00:00'),
            $this->createMedia(14, '2021-04-04 11:00:00'),
        ];

        self::assertSame([], $strategy->draft($mediaItems, Context::fromScope($mediaItems)));
    }

    #[Test]
    public function featureDrivenSeasonAggregationMatchesFallback(): void
    {
        $strategy = new SeasonOverYearsClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minYears: 2,
            minItemsPerSeason: 4,
        );

        $items = [
            $this->createMedia(201, '2019-06-01 08:00:00'),
            $this->createMedia(202, '2019-06-03 09:00:00'),
            $this->createMedia(203, '2020-08-10 10:00:00'),
            $this->createMedia(204, '2020-08-12 11:00:00'),
        ];

        $fallbackClusters = $this->normaliseClusters($strategy->draft($items, Context::fromScope($items)));

        foreach ($items as $media) {
            $media->setFeatures([
                'calendar' => ['season' => 'summer'],
            ]);
        }

        $featureClusters = $this->normaliseClusters($strategy->draft($items, Context::fromScope($items)));

        self::assertSame($fallbackClusters, $featureClusters);
    }

    #[Test]
    public function clusterWithProgressEmitsGroupingUpdates(): void
    {
        $strategy = new SeasonOverYearsClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minYears: 3,
            minItemsPerSeason: 6,
        );

        $items = [
            $this->createMedia(501, '2019-07-02 09:00:00'),
            $this->createMedia(502, '2019-07-04 10:00:00'),
            $this->createMedia(503, '2020-08-06 11:00:00'),
            $this->createMedia(504, '2020-08-08 12:00:00'),
            $this->createMedia(505, '2021-06-10 13:00:00'),
            $this->createMedia(506, '2021-06-12 14:00:00'),
        ];

        $updates = [];
        $strategy->clusterWithProgress($items, Context::fromScope($items), static function (int $done, int $max, string $stage) use (&$updates): void {
            $updates[] = ['done' => $done, 'max' => $max, 'stage' => $stage];
        });

        self::assertNotEmpty($updates);

        $groupingUpdates = array_values(array_filter(
            $updates,
            static fn (array $update): bool => str_starts_with($update['stage'], 'Gruppiere'),
        ));

        self::assertNotEmpty($groupingUpdates);
        self::assertGreaterThan(0, $groupingUpdates[0]['done']);
        self::assertGreaterThan($groupingUpdates[0]['done'], $groupingUpdates[0]['max']);

        $finalUpdate = $updates[array_key_last($updates)];
        self::assertTrue(str_starts_with($finalUpdate['stage'], 'Abgeschlossen'));
        self::assertSame($finalUpdate['max'], $finalUpdate['done']);
    }

    private function createMedia(int $id, string $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('season-over-years-%d.jpg', $id),
            takenAt: $takenAt,
        );
    }

    /**
     * @param list<array{label: string, score: float}> $sceneTags
     * @param list<string>                             $keywords
     */
    private function assignTags(Media $media, array $sceneTags, array $keywords): void
    {
        $media->setSceneTags($sceneTags);
        $media->setKeywords($keywords);
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
}
