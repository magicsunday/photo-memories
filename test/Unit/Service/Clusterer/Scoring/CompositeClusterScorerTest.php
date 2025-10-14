<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Scoring;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterBuildProgressCallbackInterface;
use MagicSunday\Memories\Service\Clusterer\Scoring\CompositeClusterScorer;
use MagicSunday\Memories\Service\Clusterer\Scoring\ClusterScoreHeuristicInterface;
use MagicSunday\Memories\Service\Clusterer\Scoring\ContentClusterScoreHeuristic;
use MagicSunday\Memories\Service\Clusterer\Scoring\DensityClusterScoreHeuristic;
use MagicSunday\Memories\Service\Clusterer\Scoring\HolidayClusterScoreHeuristic;
use MagicSunday\Memories\Service\Clusterer\Scoring\LocationClusterScoreHeuristic;
use MagicSunday\Memories\Service\Clusterer\Scoring\NoveltyHeuristic;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Service\Clusterer\Scoring\PeopleClusterScoreHeuristic;
use MagicSunday\Memories\Service\Clusterer\Scoring\PoiClusterScoreHeuristic;
use MagicSunday\Memories\Service\Clusterer\Scoring\QualityClusterScoreHeuristic;
use MagicSunday\Memories\Service\Clusterer\Scoring\RecencyClusterScoreHeuristic;
use MagicSunday\Memories\Service\Clusterer\Scoring\TemporalClusterScoreHeuristic;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use function sprintf;

final class CompositeClusterScorerTest extends TestCase
{
    #[Test]
    public function scoreCombinesHeuristicsAndAppliesBoost(): void
    {
        $mediaMap = $this->createMediaFixtures();

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $queryBuilder  = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->getMock();

        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn(array_values($mediaMap));

        $now = (new DateTimeImmutable('2024-06-01 00:00:00'))->getTimestamp();

        $heuristics = [
            new TemporalClusterScoreHeuristic(3, 0.6, 1990),
            new QualityClusterScoreHeuristic(new ClusterQualityAggregator(12.0)),
            new PeopleClusterScoreHeuristic(),
            new ContentClusterScoreHeuristic(),
            new LocationClusterScoreHeuristic(),
            new PoiClusterScoreHeuristic(['tourism/*' => 0.1]),
            new NoveltyHeuristic(),
            new HolidayClusterScoreHeuristic(new NullHolidayResolver(), 3, 0.6, 1990),
            new RecencyClusterScoreHeuristic(3, 0.6, 1990, static fn (): int => $now),
            new DensityClusterScoreHeuristic(3, 0.6, 1990),
        ];

        $scorer = new CompositeClusterScorer(
            em: $entityManager,
            heuristics: $heuristics,
            weights: [
                'quality'       => 0.22,
                'aesthetics'    => 0.08,
                'people'        => 0.16,
                'content'       => 0.09,
                'density'       => 0.10,
                'novelty'       => 0.09,
                'holiday'       => 0.07,
                'recency'       => 0.12,
                'location'      => 0.05,
                'poi'           => 0.02,
                'time_coverage' => 0.10,
            ],
            algorithmBoosts: ['vacation' => 1.45],
            algorithmGroups: ['vacation' => 'travel_and_places'],
        );

        $cluster = new ClusterDraft(
            algorithm: 'vacation',
            params: [
                'poi_label'        => 'Museum Island',
                'poi_category_key' => 'tourism',
                'poi_tags'         => ['wikidata' => 'Q123'],
                'time_range'       => [
                    'from' => (new DateTimeImmutable('2024-05-01 10:00:00'))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-05-01 10:30:00'))->getTimestamp(),
                ],
            ],
            centroid: ['lat' => 52.5208, 'lon' => 13.4095],
            members: [1, 2, 3],
        );

        $scored        = $scorer->score([$cluster]);
        $scoredCluster = $scored[0];
        $params        = $scoredCluster->getParams();

        $values = [];
        foreach ($heuristics as $heuristic) {
            $values[$heuristic->weightKey()] = $heuristic->score($scoredCluster);
        }

        $expectedRaw = 0.22 * $values['quality'] +
            0.08 * ($params['aesthetics_score'] ?? $values['quality']) +
            0.16 * $values['people'] +
            0.09 * $values['content'] +
            0.10 * $values['density'] +
            0.09 * $values['novelty'] +
            0.07 * $values['holiday'] +
            0.12 * $values['recency'] +
            0.05 * $values['location'] +
            0.02 * $values['poi'] +
            0.10 * $values['time_coverage'];

        $boosted = 0.5 * 1.45;

        self::assertEqualsWithDelta($expectedRaw, $params['pre_norm_score'], 1e-6);
        self::assertEqualsWithDelta(0.5, $params['post_norm_score'], 1e-9);
        self::assertEqualsWithDelta($boosted, $params['boosted_score'], 1e-9);
        self::assertEqualsWithDelta($boosted, $params['score'], 1e-9);
        self::assertEqualsWithDelta(1.45, $params['score_algorithm_boost'], 1e-9);
        self::assertSame('travel_and_places', $params['group']);
    }

    #[Test]
    public function scoreRespectsPersistedHeuristicMetrics(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $heuristics = [
            new QualityClusterScoreHeuristic(new ClusterQualityAggregator(12.0)),
            new PeopleClusterScoreHeuristic(),
            new PoiClusterScoreHeuristic(['tourism/*' => 0.1]),
            new RecencyClusterScoreHeuristic(3, 0.6, 1990, static fn (): int => 1_700_000_000),
        ];

        $scorer = new CompositeClusterScorer(
            em: $entityManager,
            heuristics: $heuristics,
            weights: [
                'quality'    => 0.4,
                'aesthetics' => 0.1,
                'people'     => 0.2,
                'poi'        => 0.1,
                'recency'    => 0.2,
            ],
            algorithmBoosts: [],
            algorithmGroups: ['vacation' => 'travel_and_places'],
        );

        $cluster = new ClusterDraft(
            algorithm: 'vacation',
            params: [
                'quality_avg'          => 0.45,
                'aesthetics_score'     => 0.55,
                'quality_resolution'   => 0.65,
                'quality_sharpness'    => 0.6,
                'quality_iso'          => 0.2,
                'people'               => 0.35,
                'people_count'         => 7,
                'people_unique'        => 3,
                'people_coverage'      => 0.5,
                'people_face_coverage' => 0.4,
                'poi_score'            => 0.8,
                'recency'              => 0.65,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [],
        );

        $result = $scorer->score([$cluster]);

        self::assertCount(1, $result);

        $scored = $result[0];
        $params = $scored->getParams();

        self::assertEqualsWithDelta(0.45, $params['quality_avg'], 1e-9);
        self::assertEqualsWithDelta(0.55, $params['aesthetics_score'], 1e-9);
        self::assertEqualsWithDelta(0.65, $params['quality_resolution'], 1e-9);
        self::assertEqualsWithDelta(0.6, $params['quality_sharpness'], 1e-9);
        self::assertEqualsWithDelta(0.2, $params['quality_iso'], 1e-9);
        self::assertEqualsWithDelta(0.35, $params['people'], 1e-9);
        self::assertSame(7, $params['people_count']);
        self::assertSame(3, $params['people_unique']);
        self::assertEqualsWithDelta(0.5, $params['people_coverage'], 1e-9);
        self::assertEqualsWithDelta(0.4, $params['people_face_coverage'], 1e-9);
        self::assertEqualsWithDelta(0.8, $params['poi_score'], 1e-9);
        self::assertEqualsWithDelta(0.65, $params['recency'], 1e-9);
        self::assertSame('travel_and_places', $params['group']);
    }

    #[Test]
    public function scoreReportsProgressStages(): void
    {
        $mediaMap = $this->createMediaFixtures();

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $queryBuilder  = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->getMock();

        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn(array_values($mediaMap));

        $heuristics = [
            new class implements ClusterScoreHeuristicInterface {
                public function prepare(array $clusters, array $mediaMap): void
                {
                }

                public function supports(ClusterDraft $cluster): bool
                {
                    return true;
                }

                public function enrich(ClusterDraft $cluster, array $mediaMap): void
                {
                }

                public function score(ClusterDraft $cluster): float
                {
                    return (float) ($cluster->getParams()['seed_score'] ?? 0.0);
                }

                public function weightKey(): string
                {
                    return 'quality';
                }
            },
        ];

        $scorer = new CompositeClusterScorer(
            em: $entityManager,
            heuristics: $heuristics,
            weights: ['quality' => 1.0],
        );

        $clusters = [
            new ClusterDraft('vacation', ['seed_score' => 0.4], ['lat' => 0.0, 'lon' => 0.0], [1, 2]),
            new ClusterDraft('day_album', ['seed_score' => 0.6], ['lat' => 0.0, 'lon' => 0.0], [2, 3]),
        ];

        $progress = new class implements ClusterBuildProgressCallbackInterface {
            /** @var list<array{0:string,1:string,2:int,3?:int,4?:string|null}> */
            public array $events = [];

            public function onStageStart(string $stage, int $total): void
            {
                $this->events[] = ['start', $stage, $total];
            }

            public function onStageProgress(string $stage, int $processed, int $total, ?string $detail = null): void
            {
                $this->events[] = ['progress', $stage, $processed, $total, $detail];
            }

            public function onStageFinish(string $stage, int $total): void
            {
                $this->events[] = ['finish', $stage, $total];
            }
        };

        $scorer->score($clusters, $progress);

        $expected = [
            ['start', ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA, 3],
            ['progress', ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA, 3, 3, 'Medien 3/3 geladen'],
            ['finish', ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA, 3],
            ['start', ClusterBuildProgressCallbackInterface::STAGE_SCORING, 2],
            ['progress', ClusterBuildProgressCallbackInterface::STAGE_SCORING, 1, 2, 'vacation'],
            ['progress', ClusterBuildProgressCallbackInterface::STAGE_SCORING, 2, 2, 'day_album'],
            ['finish', ClusterBuildProgressCallbackInterface::STAGE_SCORING, 2],
        ];

        self::assertSame($expected, $progress->events);
    }

    #[Test]
    public function appliesAlgorithmAndClusterWeightOverrides(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $heuristics = [
            new QualityClusterScoreHeuristic(new ClusterQualityAggregator(12.0)),
            new PeopleClusterScoreHeuristic(),
        ];

        $scorer = new CompositeClusterScorer(
            em: $entityManager,
            heuristics: $heuristics,
            weights: [
                'quality' => 0.5,
                'people'  => 0.5,
            ],
            algorithmBoosts: [],
            algorithmGroups: [],
            defaultAlgorithmGroup: 'default',
            algorithmWeightOverrides: [
                'time_similarity' => ['quality' => 0.7],
            ],
        );

        $cluster = new ClusterDraft(
            algorithm: 'time_similarity',
            params: [
                'quality_avg'            => 0.4,
                'people'                 => 0.5,
                'score_weight_overrides' => ['people' => 0.25, 'invalid' => 'x'],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [],
        );

        $scored = $scorer->score([$cluster]);

        self::assertCount(1, $scored);
        $params = $scored[0]->getParams();
        // Expect algorithm override 0.7 for quality and cluster override 0.25 for people.
        $expected = (0.7 * 0.4) + (0.25 * 0.5);
        self::assertEqualsWithDelta($expected, $params['pre_norm_score'], 1e-9);
        self::assertEqualsWithDelta(0.5, $params['post_norm_score'], 1e-9);
        self::assertEqualsWithDelta(0.5, $params['boosted_score'], 1e-9);
        self::assertEqualsWithDelta(0.5, $params['score'], 1e-9);
    }

    #[Test]
    public function appliesStorylineWeightOverrides(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $heuristics = [
            new QualityClusterScoreHeuristic(new ClusterQualityAggregator(12.0)),
            new PeopleClusterScoreHeuristic(),
        ];

        $scorer = new CompositeClusterScorer(
            em: $entityManager,
            heuristics: $heuristics,
            weights: [
                'quality' => 0.5,
                'people'  => 0.5,
            ],
            algorithmBoosts: [],
            algorithmGroups: [],
            defaultAlgorithmGroup: 'default',
            algorithmWeightOverrides: [
                'vacation' => [
                    'default' => ['quality' => 0.6],
                    'vacation.transit' => ['quality' => 0.2, 'people' => 0.8],
                ],
            ],
        );

        $cluster = new ClusterDraft(
            algorithm: 'vacation',
            params: [
                'quality_avg' => 0.4,
                'people'      => 0.5,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [],
            storyline: 'vacation.transit',
        );

        $scored  = $scorer->score([$cluster]);
        $params  = $scored[0]->getParams();
        $expected = (0.2 * 0.4) + (0.8 * 0.5);

        self::assertEqualsWithDelta($expected, $params['pre_norm_score'], 1e-9);
        self::assertEqualsWithDelta(0.5, $params['post_norm_score'], 1e-9);
    }

    #[Test]
    public function normalisesScoresPerAlgorithm(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $heuristics = [
            new class implements ClusterScoreHeuristicInterface {
                public function prepare(array $clusters, array $mediaMap): void
                {
                }

                public function supports(ClusterDraft $cluster): bool
                {
                    return true;
                }

                public function enrich(ClusterDraft $cluster, array $mediaMap): void
                {
                }

                public function score(ClusterDraft $cluster): float
                {
                    return $cluster->getParams()['seed_score'] ?? 0.0;
                }

                public function weightKey(): string
                {
                    return 'quality';
                }
            },
        ];

        $scorer = new CompositeClusterScorer(
            em: $entityManager,
            heuristics: $heuristics,
            weights: ['quality' => 1.0],
            algorithmBoosts: ['vacation' => 1.2, 'day_album' => 0.8],
        );

        $clusters = [
            new ClusterDraft('vacation', ['seed_score' => 0.1], ['lat' => 0.0, 'lon' => 0.0], []),
            new ClusterDraft('vacation', ['seed_score' => 0.5], ['lat' => 0.0, 'lon' => 0.0], []),
            new ClusterDraft('vacation', ['seed_score' => 0.9], ['lat' => 0.0, 'lon' => 0.0], []),
            new ClusterDraft('day_album', ['seed_score' => 0.2], ['lat' => 0.0, 'lon' => 0.0], []),
            new ClusterDraft('day_album', ['seed_score' => 0.7], ['lat' => 0.0, 'lon' => 0.0], []),
        ];

        $scored = $scorer->score($clusters);

        self::assertCount(5, $scored);

        $expected = [
            'vacation'  => ['0.1' => 0.1, '0.5' => 0.5, '0.9' => 0.9],
            'day_album' => ['0.2' => 0.1, '0.7' => 0.9],
        ];

        foreach ($scored as $cluster) {
            $algorithm = $cluster->getAlgorithm();
            $seed      = $cluster->getParams()['seed_score'];
            $postNorm  = $cluster->getParams()['post_norm_score'];

            self::assertEqualsWithDelta($seed, $cluster->getParams()['pre_norm_score'], 1e-9);
            $seedKey = sprintf('%.1f', $seed);
            self::assertEqualsWithDelta($expected[$algorithm][$seedKey], $postNorm, 1e-9);

            $boost = $cluster->getParams()['boosted_score'];
            $factor = $algorithm === 'vacation' ? 1.2 : 0.8;

            self::assertEqualsWithDelta($postNorm * $factor, $boost, 1e-9);
            self::assertEqualsWithDelta($boost, $cluster->getParams()['score'], 1e-9);

            self::assertEqualsWithDelta($factor, $cluster->getParams()['score_algorithm_boost'], 1e-9);
        }
    }

    /**
     * @return array<int, Media>
     */
    private function createMediaFixtures(): array
    {
        $configure = static function (Media $media): void {
            $media->setWidth(4000);
            $media->setHeight(3000);
            $media->setSharpness(0.9);
            $media->setIso(50);
            $media->setBrightness(0.55);
            $media->setContrast(0.9);
            $media->setEntropy(0.8);
            $media->setColorfulness(0.85);
            $media->setCameraModel('Canon EOS');
            $media->setPhash('abcd1234efgh5678');
            $media->setPhashPrefix('abcd1234efgh5678');
        };

        return [
            1 => $this->makeMedia(
                id: 1,
                path: __DIR__ . '/composite-1.jpg',
                takenAt: '2024-05-01 10:00:00',
                lat: 52.5200,
                lon: 13.4050,
                configure: static function (Media $media) use ($configure): void {
                    $configure($media);
                    $media->setPersons(['Alice']);
                    $media->setKeywords(['Travel', 'Friends']);
                },
            ),
            2 => $this->makeMedia(
                id: 2,
                path: __DIR__ . '/composite-2.jpg',
                takenAt: '2024-05-01 10:05:00',
                lat: 52.5210,
                lon: 13.4060,
                configure: static function (Media $media) use ($configure): void {
                    $configure($media);
                    $media->setPersons(['Bob']);
                    $media->setKeywords(['Travel']);
                },
            ),
            3 => $this->makeMedia(
                id: 3,
                path: __DIR__ . '/composite-3.jpg',
                takenAt: '2024-05-01 10:30:00',
                lat: 52.5220,
                lon: 13.4070,
                configure: static function (Media $media) use ($configure): void {
                    $configure($media);
                },
            ),
        ];
    }
}
