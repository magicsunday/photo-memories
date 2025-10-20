<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Feed;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Clusterer\TitleGeneratorInterface;
use MagicSunday\Memories\Service\Feed\CoverPickerInterface;
use MagicSunday\Memories\Service\Feed\FeedPersonalizationProfile;
use MagicSunday\Memories\Service\Feed\FeedVisibilityFilter;
use MagicSunday\Memories\Service\Feed\MemoryFeedBuilder;
use MagicSunday\Memories\Service\Feed\SeriesHighlightService;
use MagicSunday\Memories\Service\Feed\FeedUserPreferences;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

final class MemoryFeedBuilderTest extends TestCase
{
    #[Test]
    public function filtersHiddenMediaFromFeedItems(): void
    {
        $titleGen = $this->createMock(TitleGeneratorInterface::class);
        $titleGen->method('makeTitle')->willReturn('Titel');
        $titleGen->method('makeSubtitle')->willReturn('Untertitel');

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturnCallback(static function (array $members): ?Media {
            return $members[0] ?? null;
        });

        $visible = $this->buildMedia(1, false);
        $hidden  = $this->buildMedia(2, true);

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository
            ->expects(self::once())
            ->method('findByIds')
            ->with([1, 2], false)
            ->willReturn([$visible, $hidden]);

        $seriesHighlightService = new SeriesHighlightService();

        $builder = new MemoryFeedBuilder(
            $titleGen,
            $coverPicker,
            $mediaRepository,
            $seriesHighlightService,
            minScore: 0.0,
            minMembers: 1,
            maxPerDay: 5,
            maxTotal: 10,
            maxPerAlgorithm: 5,
        );

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'score'      => 0.5,
                'time_range' => ['to' => 1],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2],
        );

        $result = $builder->build([$cluster]);

        self::assertCount(1, $result);
        $item = $result[0];

        self::assertSame([1], $item->getMemberIds());
        self::assertSame(1, $item->getCoverMediaId());
    }

    private function buildMedia(int $id, bool $noShow, ?DateTimeImmutable $takenAt = null): Media
    {
        $media = new Media('path-' . $id . '.jpg', 'checksum-' . $id, 1024);

        $this->assignEntityId($media, $id);

        $media->setNoShow($noShow);

        if ($takenAt !== null) {
            $media->setTakenAt($takenAt);
        }

        return $media;
    }

    #[Test]
    public function usesCuratedOverlayWhenAvailable(): void
    {
        $titleGen = $this->createMock(TitleGeneratorInterface::class);
        $titleGen->method('makeTitle')->willReturn('Titel');
        $titleGen->method('makeSubtitle')->willReturn('Untertitel');

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturnCallback(static function (array $members): ?Media {
            return $members[0] ?? null;
        });

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository
            ->expects(self::once())
            ->method('findByIds')
            ->with([1, 2, 3, 4], false)
            ->willReturn([
                $this->buildMedia(1, false, new DateTimeImmutable('2024-01-01T10:00:00Z')),
                $this->buildMedia(2, false, new DateTimeImmutable('2024-01-01T11:00:00Z')),
                $this->buildMedia(3, false, new DateTimeImmutable('2024-01-01T12:00:00Z')),
                $this->buildMedia(4, false, new DateTimeImmutable('2024-01-01T13:00:00Z')),
            ]);

        $seriesHighlightService = new SeriesHighlightService();

        $builder = new MemoryFeedBuilder(
            $titleGen,
            $coverPicker,
            $mediaRepository,
            $seriesHighlightService,
            minScore: 0.1,
            minMembers: 2,
            maxPerDay: 5,
            maxTotal: 10,
            maxPerAlgorithm: 5,
        );

        $params = [
            'score'      => 0.8,
            'time_range' => ['to' => time()],
            'member_quality' => [
                'ordered' => [3, 1, 4],
                'summary' => [
                    'selection_profile' => ['minimum_total' => 3],
                    'selection_counts'  => ['curated' => 3],
                ],
            ],
        ];

        $cluster = new ClusterDraft(
            algorithm: 'test-algo',
            params: $params,
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3, 4],
        );
        $cluster->setCoverMediaId(4);

        $result = $builder->build([$cluster]);

        self::assertCount(1, $result);
        $item = $result[0];

        self::assertSame([3, 1, 4], $item->getMemberIds());

        $feedOverlay = $item->getParams()['member_quality']['feed_overlay'] ?? null;
        self::assertIsArray($feedOverlay);
        self::assertTrue($feedOverlay['used']);
        self::assertSame(3, $feedOverlay['minimum_total']);
        self::assertSame(3, $feedOverlay['applied_count']);
    }

    #[Test]
    public function fallsBackToChronologicalWhenCuratedBelowMinimum(): void
    {
        $titleGen = $this->createMock(TitleGeneratorInterface::class);
        $titleGen->method('makeTitle')->willReturn('Titel');
        $titleGen->method('makeSubtitle')->willReturn('Untertitel');

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturnCallback(static function (array $members): ?Media {
            return $members[0] ?? null;
        });

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository
            ->expects(self::once())
            ->method('findByIds')
            ->with([1, 2, 3, 4], false)
            ->willReturn([
                $this->buildMedia(1, false, new DateTimeImmutable('2024-01-01T09:00:00Z')),
                $this->buildMedia(2, false, new DateTimeImmutable('2024-01-01T10:00:00Z')),
                $this->buildMedia(3, false, new DateTimeImmutable('2024-01-01T11:00:00Z')),
                $this->buildMedia(4, false, new DateTimeImmutable('2024-01-01T12:00:00Z')),
            ]);

        $seriesHighlightService = new SeriesHighlightService();

        $builder = new MemoryFeedBuilder(
            $titleGen,
            $coverPicker,
            $mediaRepository,
            $seriesHighlightService,
            minScore: 0.1,
            minMembers: 3,
            maxPerDay: 5,
            maxTotal: 10,
            maxPerAlgorithm: 5,
        );

        $params = [
            'score'      => 0.75,
            'time_range' => ['to' => time()],
            'member_quality' => [
                'ordered' => [4, 2],
                'summary' => [
                    'selection_profile' => ['minimum_total' => 3],
                    'selection_counts'  => ['curated' => 2],
                ],
            ],
        ];

        $cluster = new ClusterDraft(
            algorithm: 'fallback',
            params: $params,
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3, 4],
        );
        $cluster->setCoverMediaId(3);

        $result = $builder->build([$cluster]);

        self::assertCount(1, $result);
        $item = $result[0];

        self::assertSame([3, 1, 2, 4], $item->getMemberIds());

        $feedOverlay = $item->getParams()['member_quality']['feed_overlay'] ?? null;
        self::assertIsArray($feedOverlay);
        self::assertFalse($feedOverlay['used']);
        self::assertSame(3, $feedOverlay['minimum_total']);
        self::assertArrayNotHasKey('applied_count', $feedOverlay);
    }

    #[Test]
    public function fallsBackWhenPolicyMinimumExceedsOrderedOverlay(): void
    {
        $titleGen = $this->createMock(TitleGeneratorInterface::class);
        $titleGen->method('makeTitle')->willReturn('Titel');
        $titleGen->method('makeSubtitle')->willReturn('Untertitel');

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturnCallback(static function (array $members): ?Media {
            return $members[0] ?? null;
        });

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository
            ->expects(self::once())
            ->method('findByIds')
            ->with([1, 2, 3, 4], false)
            ->willReturn([
                $this->buildMedia(1, false, new DateTimeImmutable('2024-01-01T09:00:00Z')),
                $this->buildMedia(2, false, new DateTimeImmutable('2024-01-01T10:00:00Z')),
                $this->buildMedia(3, false, new DateTimeImmutable('2024-01-01T11:00:00Z')),
                $this->buildMedia(4, false, new DateTimeImmutable('2024-01-01T12:00:00Z')),
            ]);

        $seriesHighlightService = new SeriesHighlightService();

        $builder = new MemoryFeedBuilder(
            $titleGen,
            $coverPicker,
            $mediaRepository,
            $seriesHighlightService,
            minScore: 0.1,
            minMembers: 3,
            maxPerDay: 5,
            maxTotal: 10,
            maxPerAlgorithm: 5,
        );

        $params = [
            'score'      => 0.8,
            'time_range' => ['to' => time()],
            'member_quality' => [
                'ordered' => [4, 2, 1],
                'summary' => [
                    'selection_policy_details' => ['minimum_total' => 5],
                    'selection_profile'        => ['minimum_total' => 3],
                    'selection_counts'         => ['curated' => 3],
                ],
                'member_selection' => [
                    'policy' => ['minimum_total' => 5],
                ],
            ],
        ];

        $cluster = new ClusterDraft(
            algorithm: 'policy-fallback',
            params: $params,
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3, 4],
        );
        $cluster->setCoverMediaId(4);

        $result = $builder->build([$cluster]);

        self::assertCount(1, $result);
        $item = $result[0];

        self::assertSame([4, 1, 2, 3], $item->getMemberIds());

        $feedOverlay = $item->getParams()['member_quality']['feed_overlay'] ?? null;
        self::assertIsArray($feedOverlay);
        self::assertFalse($feedOverlay['used']);
        self::assertSame(5, $feedOverlay['minimum_total']);
        self::assertArrayNotHasKey('applied_count', $feedOverlay);
    }

    #[Test]
    public function honoursQualityAndPeopleThresholds(): void
    {
        $titleGen = $this->createMock(TitleGeneratorInterface::class);
        $titleGen->method('makeTitle')->willReturn('Titel');
        $titleGen->method('makeSubtitle')->willReturn('Untertitel');

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturnCallback(static function (array $members): ?Media {
            return $members[0] ?? null;
        });

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository
            ->expects(self::once())
            ->method('findByIds')
            ->with([101, 102], false)
            ->willReturn([
                $this->buildMedia(101, false),
                $this->buildMedia(102, false),
            ]);

        $seriesHighlightService = new SeriesHighlightService();

        $builder = new MemoryFeedBuilder(
            $titleGen,
            $coverPicker,
            $mediaRepository,
            $seriesHighlightService,
            minScore: 0.3,
            minMembers: 1,
            maxPerDay: 5,
            maxTotal: 10,
            maxPerAlgorithm: 5,
            qualityFloor: 0.5,
            peopleCoverageThreshold: 0.6,
            recentDays: 30,
            staleDays: 365,
            recentScoreBonus: 0.0,
            staleScorePenalty: 0.0,
        );

        $now = time();

        $discardLowQuality = new ClusterDraft(
            algorithm: 'discard-low-quality',
            params: [
                'score'       => 0.6,
                'time_range'  => ['to' => $now],
                'quality_avg' => 0.4,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [11, 12],
        );

        $discardLowCoverage = new ClusterDraft(
            algorithm: 'discard-low-coverage',
            params: [
                'score'            => 0.7,
                'time_range'       => ['to' => $now],
                'quality_avg'      => 0.7,
                'people_count'     => 2,
                'people_coverage'  => 0.3,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [21, 22],
        );

        $keep = new ClusterDraft(
            algorithm: 'ok',
            params: [
                'score'           => 0.8,
                'time_range'      => ['to' => $now],
                'quality_avg'     => 0.9,
                'people_count'    => 3,
                'people_coverage' => 0.8,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [101, 102],
        );

        $result = $builder->build([
            $discardLowQuality,
            $discardLowCoverage,
            $keep,
        ]);

        self::assertCount(1, $result);
        self::assertSame('ok', $result[0]->getAlgorithm());
        self::assertSame('default', $result[0]->getParams()['personalisierungsProfil']);
    }

    #[Test]
    public function appliesCustomPersonalizationProfile(): void
    {
        $titleGen = $this->createMock(TitleGeneratorInterface::class);
        $titleGen->method('makeTitle')->willReturn('Titel');
        $titleGen->method('makeSubtitle')->willReturn('Untertitel');

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturnCallback(static function (array $members): ?Media {
            return $members[0] ?? null;
        });

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository
            ->expects(self::once())
            ->method('findByIds')
            ->with([301], false)
            ->willReturn([$this->buildMedia(301, false)]);

        $seriesHighlightService = new SeriesHighlightService();

        $builder = new MemoryFeedBuilder(
            $titleGen,
            $coverPicker,
            $mediaRepository,
            $seriesHighlightService,
            minScore: 0.5,
            minMembers: 1,
            maxPerDay: 5,
            maxTotal: 10,
            maxPerAlgorithm: 5,
            qualityFloor: 0.4,
            peopleCoverageThreshold: 0.5,
            recentDays: 30,
            staleDays: 365,
            recentScoreBonus: 0.0,
            staleScorePenalty: 0.0,
        );

        $cluster = new ClusterDraft(
            algorithm: 'familie',
            params: [
                'score'           => 0.35,
                'time_range'      => ['to' => time()],
                'quality_avg'     => 0.32,
                'people_count'    => 1,
                'people_coverage' => 0.25,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [301],
        );

        $customProfile = new FeedPersonalizationProfile(
            'familienfreundlich',
            minScore: 0.30,
            minMembers: 1,
            maxPerDay: 5,
            maxTotal: 10,
            maxPerAlgorithm: 5,
            qualityFloor: 0.30,
            peopleCoverageThreshold: 0.20,
            recentDays: 30,
            staleDays: 365,
            recentScoreBonus: 0.0,
            staleScorePenalty: 0.0,
        );

        $result = $builder->build([$cluster], $customProfile);

        self::assertCount(1, $result);
        self::assertSame('familie', $result[0]->getAlgorithm());
        self::assertSame('familienfreundlich', $result[0]->getParams()['personalisierungsProfil']);
    }

    #[Test]
    #[DataProvider('hiddenClusterProvider')]
    public function skipsClustersMatchingVisibilityFilter(array $params, FeedVisibilityFilter $visibility): void
    {
        $titleGen = $this->createMock(TitleGeneratorInterface::class);
        $titleGen->expects(self::never())->method('makeTitle');
        $titleGen->expects(self::never())->method('makeSubtitle');

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->expects(self::never())->method('pickCover');

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository->expects(self::never())->method('findByIds');

        $seriesHighlightService = new SeriesHighlightService();

        $builder = new MemoryFeedBuilder(
            $titleGen,
            $coverPicker,
            $mediaRepository,
            $seriesHighlightService,
            minScore: 0.1,
            minMembers: 1,
            maxPerDay: 5,
            maxTotal: 10,
            maxPerAlgorithm: 5,
        );

        $cluster = new ClusterDraft(
            algorithm: 'filtered',
            params: $params,
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2],
        );

        $result = $builder->build([$cluster], null, $visibility);

        self::assertSame([], $result);
    }

    #[Test]
    public function appliesFavouritePersonBoostFromPreferences(): void
    {
        $titleGen = $this->createMock(TitleGeneratorInterface::class);
        $titleGen->method('makeTitle')->willReturn('Titel');
        $titleGen->method('makeSubtitle')->willReturn('Untertitel');

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturnCallback(static function (array $members): ?Media {
            return $members[0] ?? null;
        });

        $media = $this->buildMedia(1, false, new DateTimeImmutable('2024-01-01T10:00:00Z'));
        $media->setPersons(['Alice']);

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository->method('findByIds')->with([1], false)->willReturn([$media]);

        $seriesHighlightService = new SeriesHighlightService();

        $builder = new MemoryFeedBuilder(
            $titleGen,
            $coverPicker,
            $mediaRepository,
            $seriesHighlightService,
            minScore: 0.0,
            minMembers: 1,
            maxPerDay: 5,
            maxTotal: 10,
            maxPerAlgorithm: 5,
            favouritePersonMultiplier: 1.2,
        );

        $cluster = new ClusterDraft(
            algorithm: 'people_boost',
            params: [
                'score'      => 0.5,
                'persons'    => ['Alice'],
                'time_range' => ['from' => 1_700_000_000, 'to' => 1_700_000_100],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1],
        );

        $preferences = new FeedUserPreferences(
            'user',
            'default',
            [],
            [],
            [],
            [],
            [],
            [],
            ['Alice'],
            [],
        );

        $items = $builder->build([$cluster], null, null, $preferences);
        self::assertCount(1, $items);

        $item = $items[0];
        self::assertEqualsWithDelta(0.6, $item->getScore(), 1e-6);

        $preference = $item->getParams()['score_preference'] ?? null;
        self::assertIsArray($preference);
        self::assertSame(1.2, $preference['multiplier']);
        self::assertSame(['Alice'], $preference['favourite_persons']);
    }

    #[Test]
    public function appliesNegativePlacePenaltyFromPreferences(): void
    {
        $titleGen = $this->createMock(TitleGeneratorInterface::class);
        $titleGen->method('makeTitle')->willReturn('Titel');
        $titleGen->method('makeSubtitle')->willReturn('Untertitel');

        $coverPicker = $this->createMock(CoverPickerInterface::class);
        $coverPicker->method('pickCover')->willReturnCallback(static function (array $members): ?Media {
            return $members[0] ?? null;
        });

        $media = $this->buildMedia(5, false, new DateTimeImmutable('2024-01-01T10:00:00Z'));

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository->method('findByIds')->with([5], false)->willReturn([$media]);

        $seriesHighlightService = new SeriesHighlightService();

        $builder = new MemoryFeedBuilder(
            $titleGen,
            $coverPicker,
            $mediaRepository,
            $seriesHighlightService,
            minScore: 0.0,
            minMembers: 1,
            maxPerDay: 5,
            maxTotal: 10,
            maxPerAlgorithm: 5,
            favouritePersonMultiplier: 1.0,
            favouritePlaceMultiplier: 1.0,
            negativePersonMultiplier: 1.0,
            negativePlaceMultiplier: 0.6,
            negativeDateMultiplier: 1.0,
        );

        $cluster = new ClusterDraft(
            algorithm: 'place_penalty',
            params: [
                'score'      => 0.7,
                'place'      => 'Berlin',
                'time_range' => ['from' => 1_700_000_000, 'to' => 1_700_000_200],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [5],
        );

        $preferences = new FeedUserPreferences(
            'user',
            'default',
            [],
            [],
            [],
            [],
            ['Berlin'],
            [],
            [],
            [],
        );

        $items = $builder->build([$cluster], null, null, $preferences);
        self::assertCount(1, $items);

        $item = $items[0];
        self::assertEqualsWithDelta(0.42, $item->getScore(), 1e-6);

        $preference = $item->getParams()['score_preference'] ?? null;
        self::assertIsArray($preference);
        self::assertSame(0.6, $preference['multiplier']);
        self::assertSame(['Berlin'], $preference['hidden_places']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: FeedVisibilityFilter}>
     */
    public static function hiddenClusterProvider(): iterable
    {
        $from = strtotime('2024-01-05T10:00:00Z');
        $to   = $from + 3600;

        return [
            'person' => [
                [
                    'score'      => 0.9,
                    'time_range' => ['from' => $from, 'to' => $to],
                    'persons'    => [42],
                ],
                new FeedVisibilityFilter(['42']),
            ],
            'pet' => [
                [
                    'score'      => 0.9,
                    'time_range' => ['from' => $from, 'to' => $to],
                    'pet_ids'    => ['pet-7'],
                ],
                new FeedVisibilityFilter([], ['pet-7']),
            ],
            'place' => [
                [
                    'score'      => 0.9,
                    'time_range' => ['from' => $from, 'to' => $to],
                    'place'      => 'Berlin',
                ],
                new FeedVisibilityFilter([], [], ['Berlin']),
            ],
            'date' => [
                [
                    'score'      => 0.9,
                    'time_range' => ['from' => $from, 'to' => $to],
                ],
                new FeedVisibilityFilter([], [], [], ['2024-01-05']),
            ],
        ];
    }
}
