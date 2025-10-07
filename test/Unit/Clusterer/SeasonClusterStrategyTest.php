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
use MagicSunday\Memories\Clusterer\SeasonClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class SeasonClusterStrategyTest extends TestCase
{
    #[Test]
    public function groupsItemsBySeasonPerYear(): void
    {
        $strategy = new SeasonClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minItemsPerSeason: 4,
        );

        $mediaItems = [
            $this->createMedia(1, '2023-12-15 09:00:00'),
            $this->createMedia(2, '2024-01-05 11:00:00'),
            $this->createMedia(3, '2024-02-10 14:00:00'),
            $this->createMedia(4, '2024-02-15 08:30:00'),
            $this->createMedia(5, '2024-07-01 12:00:00'),
        ];

        $this->assignTags($mediaItems[0], [
            ['label' => 'Schnee', 'score' => 0.85],
            ['label' => 'Familie', 'score' => 0.8],
        ], ['Winter', 'Familie']);
        $this->assignTags($mediaItems[1], [
            ['label' => 'Schnee', 'score' => 0.9],
            ['label' => 'Stadt', 'score' => 0.6],
        ], ['Winter', 'Stadt']);
        $this->assignTags($mediaItems[2], [
            ['label' => 'Schnee', 'score' => 0.7],
        ], ['Winter']);
        $this->assignTags($mediaItems[3], [
            ['label' => 'Schnee', 'score' => 0.65],
        ], ['Winter']);

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('season', $cluster->getAlgorithm());
        $params = $cluster->getParams();
        self::assertSame('Winter', $params['label']);
        self::assertSame(2024, $params['year']);
        self::assertSame([1, 2, 3, 4], $cluster->getMembers());
        self::assertArrayHasKey('scene_tags', $params);
        self::assertArrayHasKey('keywords', $params);
        self::assertArrayHasKey('quality_avg', $params);
        $sceneTags = $params['scene_tags'];
        self::assertCount(3, $sceneTags);
        self::assertSame('Schnee', $sceneTags[0]['label']);
        self::assertEqualsWithDelta(0.9, $sceneTags[0]['score'], 0.0001);
        self::assertSame('Familie', $sceneTags[1]['label']);
        self::assertEqualsWithDelta(0.8, $sceneTags[1]['score'], 0.0001);
        self::assertSame('Stadt', $sceneTags[2]['label']);
        self::assertEqualsWithDelta(0.6, $sceneTags[2]['score'], 0.0001);
        self::assertSame(['Winter', 'Familie', 'Stadt'], $params['keywords']);
    }

    #[Test]
    public function skipsGroupsBelowMinimum(): void
    {
        $strategy = new SeasonClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minItemsPerSeason: 3,
        );

        $mediaItems = [
            $this->createMedia(11, '2024-06-01 10:00:00'),
            $this->createMedia(12, '2024-06-05 11:00:00'),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    #[Test]
    public function featureDrivenSeasonMatchesFallback(): void
    {
        $strategy = new SeasonClusterStrategy(
            locationHelper: LocationHelper::createDefault(),
            minItemsPerSeason: 4,
        );

        $items = [
            $this->createMedia(101, '2022-12-12 07:45:00'),
            $this->createMedia(102, '2023-01-03 09:00:00'),
            $this->createMedia(103, '2023-02-11 10:15:00'),
            $this->createMedia(104, '2023-02-18 11:30:00'),
        ];

        $fallbackClusters = $this->normaliseClusters($strategy->cluster($items));

        foreach ($items as $media) {
            $media->setFeatures([
                'season' => 'winter',
            ]);
        }

        $featureClusters = $this->normaliseClusters($strategy->cluster($items));

        self::assertSame($fallbackClusters, $featureClusters);
    }

    private function createMedia(int $id, string $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('season-%d.jpg', $id),
            takenAt: $takenAt,
            configure: static function (Media $media): void {
                $media->setWidth(4032);
                $media->setHeight(3024);
                $media->setSharpness(0.7);
                $media->setIso(125);
                $media->setBrightness(0.58);
                $media->setContrast(0.65);
                $media->setEntropy(0.72);
                $media->setColorfulness(0.81);
            },
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
