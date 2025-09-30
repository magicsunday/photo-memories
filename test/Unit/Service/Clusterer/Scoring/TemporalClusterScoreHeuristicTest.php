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
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Scoring\TemporalClusterScoreHeuristic;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class TemporalClusterScoreHeuristicTest extends TestCase
{
    #[Test]
    public function enrichCalculatesTemporalMetrics(): void
    {
        $heuristic = new TemporalClusterScoreHeuristic(
            timeRangeMinSamples: 3,
            timeRangeMinCoverage: 0.6,
            minValidYear: 1990,
        );

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-01-01 10:00:00'))->getTimestamp(),
                    'to' => (new DateTimeImmutable('2024-01-01 12:00:00'))->getTimestamp(),
                ],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3],
        );

        $mediaMap = [
            1 => $this->makeMedia(id: 1, path: __DIR__ . '/temporal-1.jpg', takenAt: '2024-01-01 10:15:00'),
            2 => $this->makeMedia(id: 2, path: __DIR__ . '/temporal-2.jpg', takenAt: '2024-01-01 11:30:00'),
            3 => $this->makeMedia(id: 3, path: __DIR__ . '/temporal-3.jpg'),
        ];

        $heuristic->enrich($cluster, $mediaMap);

        $params = $cluster->getParams();
        self::assertSame(7200, $params['temporal_duration_seconds']);
        $coverage = 2 / 3;
        self::assertEqualsWithDelta($coverage, $params['temporal_coverage'], 1e-9);

        $hours    = 7200 / 3600.0;
        $spanPart = 1.0 - (($hours - 0.5) / 47.5) * 0.4;
        $expected = 0.55 * $coverage + 0.45 * $spanPart;

        self::assertEqualsWithDelta($expected, $heuristic->score($cluster), 1e-6);
        self::assertSame('time_coverage', $heuristic->weightKey());
    }
}
