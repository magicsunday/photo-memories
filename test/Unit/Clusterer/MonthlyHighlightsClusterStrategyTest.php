<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\MonthlyHighlightsClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MonthlyHighlightsClusterStrategyTest extends TestCase
{
    #[Test]
    public function emitsClusterPerEligibleMonth(): void
    {
        $strategy = new MonthlyHighlightsClusterStrategy(
            timezone: 'UTC',
            minItemsPerMonth: 4,
            minDistinctDays: 3,
        );

        $mediaItems = [
            $this->createMedia(1, '2023-03-01 08:00:00'),
            $this->createMedia(2, '2023-03-02 09:00:00'),
            $this->createMedia(3, '2023-03-02 10:00:00'),
            $this->createMedia(4, '2023-03-05 18:00:00'),
            $this->createMedia(5, '2023-04-01 12:00:00'),
            $this->createMedia(6, '2023-04-02 12:00:00'),
            $this->createMedia(7, '2023-04-03 12:00:00'),
        ];

        foreach ([0, 1, 2, 3] as $index) {
            $this->assignTags($mediaItems[$index], [
                ['label' => 'Stadt', 'score' => 0.75 + ($index * 0.01)],
            ], ['Highlights', 'Stadt']);
        }

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('monthly_highlights', $cluster->getAlgorithm());
        $params = $cluster->getParams();
        self::assertSame(2023, $params['year']);
        self::assertSame(3, $params['month']);
        self::assertSame([1, 2, 3, 4], $cluster->getMembers());
        self::assertArrayHasKey('scene_tags', $params);
        self::assertArrayHasKey('keywords', $params);
        $sceneTags = $params['scene_tags'];
        self::assertCount(1, $sceneTags);
        self::assertSame('Stadt', $sceneTags[0]['label']);
        self::assertEqualsWithDelta(0.78, $sceneTags[0]['score'], 0.0001);
        self::assertSame(['Highlights', 'Stadt'], $params['keywords']);
    }

    #[Test]
    public function enforcesDistinctDayThreshold(): void
    {
        $strategy = new MonthlyHighlightsClusterStrategy(
            timezone: 'UTC',
            minItemsPerMonth: 4,
            minDistinctDays: 4,
        );

        $mediaItems = [
            $this->createMedia(11, '2023-05-01 08:00:00'),
            $this->createMedia(12, '2023-05-01 09:00:00'),
            $this->createMedia(13, '2023-05-02 09:00:00'),
            $this->createMedia(14, '2023-05-03 09:00:00'),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    private function createMedia(int $id, string $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('monthly-%d.jpg', $id),
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
}
