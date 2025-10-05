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
            $this->createMedia(1, '2023-12-15 09:00:00', ['season' => 'winter']),
            $this->createMedia(2, '2024-01-05 11:00:00', ['season' => 'winter']),
            $this->createMedia(3, '2024-02-10 14:00:00', ['season' => 'winter']),
            $this->createMedia(4, '2024-02-15 08:30:00', ['season' => 'winter']),
            $this->createMedia(5, '2024-07-01 12:00:00', ['season' => 'summer']),
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
    public function featureBasedAndFallbackGroupingMatch(): void
    {
        $strategy = new SeasonClusterStrategy(minItemsPerSeason: 2);

        $dataset = [
            ['id' => 21, 'takenAt' => '2022-12-28 08:00:00', 'season' => 'winter'],
            ['id' => 22, 'takenAt' => '2023-01-06 09:00:00', 'season' => 'winter'],
            ['id' => 23, 'takenAt' => '2023-07-05 11:30:00', 'season' => 'summer'],
            ['id' => 24, 'takenAt' => '2023-07-12 15:45:00', 'season' => 'summer'],
        ];

        $withFeatures = [];
        $fallbackOnly = [];

        foreach ($dataset as $row) {
            $withFeatures[] = $this->createMedia($row['id'], $row['takenAt'], ['season' => $row['season']]);
            $fallbackOnly[] = $this->createMedia($row['id'], $row['takenAt']);
        }

        $clustersWithFeatures = $strategy->cluster($withFeatures);
        $clustersWithoutFeatures = $strategy->cluster($fallbackOnly);

        self::assertSame(
            $this->normaliseClusters($clustersWithoutFeatures),
            $this->normaliseClusters($clustersWithFeatures),
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
            filename: sprintf('season-%d.jpg', $id),
            takenAt: $takenAt,
            configure: $features !== null
                ? static function (Media $media) use ($features): void {
                    $media->setFeatures($features);
                }
                : null,
        );
    }
}
