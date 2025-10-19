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
use MagicSunday\Memories\Clusterer\MonthlyHighlightsClusterStrategy;
use MagicSunday\Memories\Entity\Enum\ContentKind;
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

        $clusters = $strategy->draft($mediaItems, Context::fromScope($mediaItems));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('monthly_highlights', $cluster->getAlgorithm());
        $params = $cluster->getParams();
        self::assertSame(2023, $params['year']);
        self::assertSame(3, $params['month']);
        self::assertSame([1, 2, 3, 4], $cluster->getMembers());
        self::assertArrayHasKey('scene_tags', $params);
        self::assertArrayHasKey('keywords', $params);
        self::assertArrayHasKey('quality_avg', $params);
        self::assertIsFloat($params['quality_avg']);
        self::assertArrayHasKey('people', $params);
        self::assertArrayHasKey('people_count', $params);
        self::assertArrayHasKey('people_unique', $params);
        self::assertArrayHasKey('people_coverage', $params);
        self::assertArrayHasKey('people_face_coverage', $params);
        self::assertSame(0.0, $params['people']);
        self::assertSame(0, $params['people_count']);
        self::assertSame(0, $params['people_unique']);
        self::assertSame(0.0, $params['people_coverage']);
        self::assertSame(0.0, $params['people_face_coverage']);
        $sceneTags = $params['scene_tags'];
        self::assertCount(1, $sceneTags);
        self::assertSame('Stadt', $sceneTags[0]['label']);
        self::assertEqualsWithDelta(0.78, $sceneTags[0]['score'], 0.0001);
        self::assertSame(['Highlights', 'Stadt'], $params['keywords']);
        self::assertSame('Unbekanntes Gerät', $params['device_primary_label']);
        self::assertEqualsWithDelta(1.0, $params['device_primary_share'], 0.0001);
        self::assertSame(1, $params['device_variants']);
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

        self::assertSame([], $strategy->draft($mediaItems, Context::fromScope($mediaItems)));
    }

    #[Test]
    public function sortsClustersByMostRecentMonthFirst(): void
    {
        $strategy = new MonthlyHighlightsClusterStrategy(
            timezone: 'UTC',
            minItemsPerMonth: 2,
            minDistinctDays: 2,
        );

        $mediaItems = [
            // March 2023 appears first in the input to verify explicit sorting.
            $this->createMedia(21, '2023-03-15 08:30:00'),
            $this->createMedia(22, '2024-02-01 09:00:00'),
            $this->createMedia(23, '2024-01-01 10:00:00'),
            $this->createMedia(24, '2024-02-18 11:00:00'),
            $this->createMedia(25, '2024-01-12 12:00:00'),
            $this->createMedia(26, '2023-03-22 13:00:00'),
        ];

        $clusters = $strategy->draft($mediaItems, Context::fromScope($mediaItems));

        self::assertCount(3, $clusters);

        $timeline = array_map(
            static fn (ClusterDraft $cluster): array => [
                'year'  => $cluster->getParams()['year'],
                'month' => $cluster->getParams()['month'],
            ],
            $clusters,
        );

        self::assertSame([
            ['year' => 2024, 'month' => 2],
            ['year' => 2024, 'month' => 1],
            ['year' => 2023, 'month' => 3],
        ], $timeline);
    }

    private function createMedia(int $id, string $takenAt): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('monthly-%d.jpg', $id),
            takenAt: $takenAt,
            configure: static function (Media $media): void {
                $media->setWidth(4032);
                $media->setHeight(3024);
                $media->setSharpness(0.7);
                $media->setIso(200);
                $media->setBrightness(0.59);
                $media->setContrast(0.63);
                $media->setCameraMake(null);
                $media->setCameraModel(null);
                $media->setContentKind(ContentKind::PHOTO);
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
}
