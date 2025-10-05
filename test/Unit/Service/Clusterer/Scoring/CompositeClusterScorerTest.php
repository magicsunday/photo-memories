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
use MagicSunday\Memories\Service\Clusterer\Scoring\CompositeClusterScorer;
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

        $expected = 0.22 * $values['quality'] +
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

        $boosted = $expected * 1.45;

        self::assertEqualsWithDelta($boosted, $params['score'], 1e-6);
        self::assertEqualsWithDelta(1.45, $params['score_algorithm_boost'], 1e-9);
        self::assertSame('travel_and_places', $params['group']);
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
