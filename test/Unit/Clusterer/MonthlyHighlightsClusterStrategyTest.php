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
use MagicSunday\Memories\Clusterer\Context;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\MonthlyHighlightsClusterStrategy;
use MagicSunday\Memories\Entity\Enum\ContentKind;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

use function array_keys;
use function array_map;
use function array_sum;
use function range;
use function sprintf;
use function strtotime;

final class MonthlyHighlightsClusterStrategyTest extends TestCase
{
    #[Test]
    public function buildsEventScopedClustersWithMetadata(): void
    {
        $strategy = $this->createStrategy(
            minItemsPerMonth: 7,
            minDistinctDays: 2,
            minItemsPerEvent: 3,
            minSpacingSeconds: 6 * 3600,
        );

        $eventA = $this->createEvent(1, '2023-03-01 08:00:00', 3, 52.52, 13.405);
        $eventB = $this->createEvent(4, '2023-03-05 17:15:00', 4, 48.137, 11.575);

        foreach ([4, 5, 6, 7] as $index) {
            $this->assignTags(
                $eventB[$index - 4],
                [
                    ['label' => 'Stadt', 'score' => 0.8],
                    ['label' => 'Nacht', 'score' => 0.62],
                ],
                ['Highlights', 'München']
            );
        }

        $mediaItems = [...$eventA, ...$eventB];
        $clusters   = $strategy->draft($mediaItems, Context::fromScope($mediaItems));

        self::assertCount(2, $clusters);

        $latest = $clusters[0];
        self::assertSame([4, 5, 6, 7], $latest->getMembers());

        $params = $latest->getParams();
        self::assertSame('monthly_highlights', $latest->getAlgorithm());
        self::assertSame(2023, $params['year']);
        self::assertSame(3, $params['month']);
        self::assertSame('März 2023', $params['label']);
        self::assertSame(4, $params['members_count']);
        self::assertArrayHasKey('time_range', $params);
        self::assertSame(strtotime('2023-03-05 17:15:00 UTC'), $params['time_range']['from']);
        self::assertSame(strtotime('2023-03-05 18:45:00 UTC'), $params['time_range']['to']);

        self::assertArrayHasKey('score_mix', $params);
        self::assertSame(['quantity', 'quality', 'people', 'recency'], array_keys($params['score_mix']));
        self::assertArrayHasKey('score_total', $params);
        self::assertEqualsWithDelta($params['score_total'], array_sum($params['score_mix']), 0.01);

        self::assertArrayHasKey('scene_tags', $params);
        self::assertArrayHasKey('keywords', $params);
        self::assertSame(['Highlights', 'München'], $params['keywords']);

        self::assertArrayHasKey('quality_avg', $params);
        self::assertArrayHasKey('people', $params);
        self::assertArrayHasKey('device_primary_label', $params);
    }

    #[Test]
    public function enforcesDistinctDayThreshold(): void
    {
        $strategy = $this->createStrategy(
            minItemsPerMonth: 4,
            minDistinctDays: 4,
            minItemsPerEvent: 2,
        );

        $mediaItems = [
            $this->createMedia(11, '2023-05-01 08:00:00'),
            $this->createMedia(12, '2023-05-01 09:00:00'),
            $this->createMedia(13, '2023-05-02 09:00:00'),
            $this->createMedia(14, '2023-05-03 09:30:00'),
        ];

        self::assertSame([], $strategy->draft($mediaItems, Context::fromScope($mediaItems)));
    }

    #[Test]
    public function sortsClustersByMostRecentMonthFirst(): void
    {
        $strategy = $this->createStrategy(
            minItemsPerMonth: 3,
            minDistinctDays: 1,
            minSpacingSeconds: 0,
        );

        $mediaItems = [
            ...$this->createEvent(21, '2024-02-02 09:00:00', 3),
            ...$this->createEvent(24, '2024-01-10 11:00:00', 3),
            ...$this->createEvent(27, '2023-03-15 08:30:00', 3),
        ];

        $clusters = $strategy->draft($mediaItems, Context::fromScope($mediaItems));

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

    #[Test]
    public function capsClustersPerMonthAtFive(): void
    {
        $strategy = $this->createStrategy(
            minItemsPerMonth: 15,
            minDistinctDays: 5,
            minItemsPerEvent: 3,
            minSpacingSeconds: 0,
        );

        $mediaItems = [];
        $startId    = 1;
        $day        = new DateTimeImmutable('2023-06-01 09:00:00');

        foreach (range(0, 5) as $offset) {
            $mediaItems = [...$mediaItems, ...$this->createEvent($startId, $day->modify(sprintf('+%d days', $offset))->format('Y-m-d H:i:s'), 3)];
            $startId   += 3;
        }

        $clusters = $strategy->draft($mediaItems, Context::fromScope($mediaItems));

        self::assertCount(5, $clusters);
        $memberSets = array_map(static fn (ClusterDraft $cluster): array => $cluster->getMembers(), $clusters);
        self::assertNotContains([1, 2, 3], $memberSets);
    }

    #[Test]
    public function enforcesTemporalSpacingWithinMonth(): void
    {
        $strategy = $this->createStrategy(
            minItemsPerMonth: 9,
            minDistinctDays: 3,
            minItemsPerEvent: 3,
            minSpacingSeconds: 30 * 3600,
        );

        $eventA = $this->createEvent(1, '2023-03-01 08:00:00', 3);
        $eventB = $this->createEvent(4, '2023-03-02 00:30:00', 3);
        $eventC = $this->createEvent(7, '2023-03-05 09:00:00', 3);

        $scope    = [...$eventA, ...$eventB, ...$eventC];
        $clusters = $strategy->draft($scope, Context::fromScope($scope));

        self::assertCount(2, $clusters);
        self::assertSame([7, 8, 9], $clusters[0]->getMembers());
        self::assertSame([4, 5, 6], $clusters[1]->getMembers());

        $memberSets = array_map(static fn (ClusterDraft $cluster): array => $cluster->getMembers(), $clusters);
        self::assertNotContains([1, 2, 3], $memberSets);
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

    private function createStrategy(
        string $timezone = 'UTC',
        int $minItemsPerMonth = 6,
        int $minDistinctDays = 2,
        int $maxEventsPerMonth = 5,
        int $minItemsPerEvent = 3,
        int $minSpacingSeconds = 6 * 3600,
        int $eventWindowSeconds = 7_200,
        float $eventRadiusMeters = 800.0,
    ): MonthlyHighlightsClusterStrategy {
        return new MonthlyHighlightsClusterStrategy(
            LocationHelper::createDefault('de'),
            timezone: $timezone,
            minItemsPerMonth: $minItemsPerMonth,
            minDistinctDays: $minDistinctDays,
            maxEventsPerMonth: $maxEventsPerMonth,
            minItemsPerEvent: $minItemsPerEvent,
            eventWindowSeconds: $eventWindowSeconds,
            eventRadiusMeters: $eventRadiusMeters,
            minSpacingSeconds: $minSpacingSeconds,
        );
    }

    /**
     * @return list<Media>
     */
    private function createEvent(int $startId, string $startAt, int $count, float $lat = 52.52, float $lon = 13.405): array
    {
        $items  = [];
        $moment = new DateTimeImmutable($startAt);

        for ($i = 0; $i < $count; ++$i) {
            $items[] = $this->createMedia($startId + $i, $moment->add(new DateInterval(sprintf('PT%dM', $i * 30)))->format('Y-m-d H:i:s'), $lat, $lon);
        }

        return $items;
    }

    private function createMedia(int $id, string $takenAt, float $lat = 52.52, float $lon = 13.405): Media
    {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('monthly-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            configure: static function (Media $media): void {
                $media->setWidth(4032);
                $media->setHeight(3024);
                $media->setSharpness(0.72);
                $media->setIso(200);
                $media->setBrightness(0.59);
                $media->setContrast(0.63);
                $media->setCameraMake(null);
                $media->setCameraModel(null);
                $media->setContentKind(ContentKind::PHOTO);
            },
        );
    }
}
