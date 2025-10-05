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
use PHPUnit\Framework\Attributes\Test;

final class SeasonClusterStrategyTest extends TestCase
{
    #[Test]
    public function groupsItemsBySeasonPerYear(): void
    {
        $strategy = new SeasonClusterStrategy(minItemsPerSeason: 4);

        $mediaItems = [
            $this->createMedia(1, '2023-12-15 09:00:00'),
            $this->createMedia(2, '2024-01-05 11:00:00'),
            $this->createMedia(3, '2024-02-10 14:00:00'),
            $this->createMedia(4, '2024-02-15 08:30:00'),
            $this->createMedia(5, '2024-07-01 12:00:00'),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('season', $cluster->getAlgorithm());
        self::assertSame('Winter', $cluster->getParams()['label']);
        self::assertSame(2024, $cluster->getParams()['year']);
        self::assertSame([1, 2, 3, 4], $cluster->getMembers());
    }

    #[Test]
    public function skipsGroupsBelowMinimum(): void
    {
        $strategy = new SeasonClusterStrategy(minItemsPerSeason: 3);

        $mediaItems = [
            $this->createMedia(11, '2024-06-01 10:00:00'),
            $this->createMedia(12, '2024-06-05 11:00:00'),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    #[Test]
    public function featureDrivenSeasonMatchesFallback(): void
    {
        $strategy = new SeasonClusterStrategy(minItemsPerSeason: 4);

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
        );
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
