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
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Scoring\NoveltyHeuristic;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class NoveltyHeuristicTest extends TestCase
{
    #[Test]
    public function timeRaritySamplesAllDaysCoveredByTimeRange(): void
    {
        $heuristic = new NoveltyHeuristic(
            weights: [
                'staypoint'      => 0.0,
                'rare_staypoint' => 0.0,
                'time'           => 1.0,
                'device'         => 0.0,
                'content'        => 0.0,
                'history'        => 0.0,
            ],
        );

        $from = (new DateTimeImmutable('2024-03-10 23:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $to   = (new DateTimeImmutable('2024-03-11 01:00:00', new DateTimeZone('UTC')))->getTimestamp();

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'time_range' => ['from' => $from, 'to' => $to],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2],
        );

        $mediaMap = [
            1 => $this->createMedia(1, '2024-03-10 23:00:00'),
            2 => $this->createMedia(2, '2024-03-11 01:00:00'),
        ];

        for ($i = 0; $i < 9; ++$i) {
            $id            = 100 + $i;
            $mediaMap[$id] = $this->createMedia($id, '2024-03-10 12:00:00');
        }

        $stats = $heuristic->buildCorpusStats($mediaMap, [$cluster]);

        $score = $heuristic->computeNovelty($cluster, $mediaMap, $stats);

        $heuristic->prepare([$cluster], $mediaMap);
        $heuristic->enrich($cluster, $mediaMap);

        self::assertEqualsWithDelta(0.45, $score, 1e-9);
        self::assertEqualsWithDelta(0.45, $cluster->getParams()['novelty'], 1e-9);
        self::assertEqualsWithDelta(0.45, $heuristic->score($cluster), 1e-9);
        self::assertSame('novelty', $heuristic->weightKey());
    }

    #[Test]
    public function staypointRarityUsesIndex(): void
    {
        $heuristic = new NoveltyHeuristic(
            weights: [
                'staypoint'      => 1.0,
                'rare_staypoint' => 0.0,
                'time'           => 0.0,
                'device'         => 0.0,
                'content'        => 0.0,
                'history'        => 0.0,
            ],
        );

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'staypoints' => [
                    'keys'   => [1 => '2024-03-10:100:200', 2 => '2024-03-10:100:200'],
                    'counts' => [
                        '2024-03-10:100:200' => 2,
                        '2024-02-01:400:800' => 20,
                    ],
                ],
            ],
            centroid: ['lat' => 10.0, 'lon' => 10.0],
            members: [1, 2],
        );

        $mediaMap = [
            1 => $this->createMedia(1, '2024-03-10 10:00:00'),
            2 => $this->createMedia(2, '2024-03-10 11:00:00'),
        ];

        $stats = $heuristic->buildCorpusStats($mediaMap, [$cluster]);
        $score = $heuristic->computeNovelty($cluster, $mediaMap, $stats);

        self::assertEqualsWithDelta(0.9, $score, 1e-9);
    }

    #[Test]
    public function rareStaypointRatioReflectsCorpusCounts(): void
    {
        $heuristic = new NoveltyHeuristic(
            rareStaypointThreshold: 5,
            weights: [
                'staypoint'      => 0.0,
                'rare_staypoint' => 1.0,
                'time'           => 0.0,
                'device'         => 0.0,
                'content'        => 0.0,
                'history'        => 0.0,
            ],
        );

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'staypoints' => [
                    'keys'   => [1 => 'rare', 2 => 'common'],
                    'counts' => ['rare' => 1, 'common' => 12],
                ],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2],
        );

        $mediaMap = [
            1 => $this->createMedia(1, '2023-05-10 09:00:00'),
            2 => $this->createMedia(2, '2023-05-10 09:10:00'),
        ];

        $stats = $heuristic->buildCorpusStats($mediaMap, [$cluster]);
        $score = $heuristic->computeNovelty($cluster, $mediaMap, $stats);

        self::assertEqualsWithDelta(0.5, $score, 1e-9);
    }

    #[Test]
    public function historyPenaltyRewardsNovelWindows(): void
    {
        $heuristic = new NoveltyHeuristic(
            weights: [
                'staypoint'      => 0.0,
                'rare_staypoint' => 0.0,
                'time'           => 0.0,
                'device'         => 0.0,
                'content'        => 0.0,
                'history'        => 1.0,
            ],
        );

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'novelty_history' => [
                    'phash_prefixes' => ['abcde' => 12, 'fffff' => 0],
                    'day_windows'    => ['0310:0' => 6, '0310:1' => 0],
                ],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2],
        );

        $mediaMap = [
            1 => $this->createMediaWithPhash(1, '2024-03-10 00:30:00', 'abcde0000000000'),
            2 => $this->createMediaWithPhash(2, '2024-03-10 02:05:00', 'abcde0000aaaaaa'),
        ];

        $stats = $heuristic->buildCorpusStats($mediaMap, [$cluster]);
        $score = $heuristic->computeNovelty($cluster, $mediaMap, $stats);

        self::assertEqualsWithDelta(0.0, $score, 1e-9);

        $freshCluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'novelty_history' => [
                    'phash_prefixes' => ['abcde' => 12, 'fffff' => 0],
                    'day_windows'    => ['0310:0' => 6, '0310:1' => 0],
                ],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [3],
        );

        $freshMediaMap = $mediaMap + [
            3 => $this->createMediaWithPhash(3, '2024-03-10 10:15:00', 'fffff9999999999'),
        ];

        $freshStats = $heuristic->buildCorpusStats($freshMediaMap, [$freshCluster]);
        $freshScore = $heuristic->computeNovelty($freshCluster, $freshMediaMap, $freshStats);

        self::assertEqualsWithDelta(1.0, $freshScore, 1e-9);
    }

    private function createMedia(int $id, string $takenAt): Media
    {
        return $this->makeMedia(
            id: $id,
            path: __DIR__ . sprintf('/novelty-%d.jpg', $id),
            takenAt: $takenAt,
        );
    }

    private function createMediaWithPhash(int $id, string $takenAt, string $phash): Media
    {
        return $this->makeMedia(
            id: $id,
            path: __DIR__ . sprintf('/novelty-phash-%d.jpg', $id),
            takenAt: $takenAt,
            configure: static function (Media $media) use ($phash): void {
                $media->setPhash($phash);
            },
        );
    }
}
