<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Scoring;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Scoring\DensityClusterScoreHeuristic;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DensityClusterScoreHeuristicTest extends TestCase
{
    #[Test]
    public function enrichCalculatesDensityFromTimeRange(): void
    {
        $heuristic = new DensityClusterScoreHeuristic(
            timeRangeMinSamples: 3,
            timeRangeMinCoverage: 0.6,
            minValidYear: 1990,
        );

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'time_range' => [
                    'from' => 1_700_000_000,
                    'to'   => 1_700_000_600,
                ],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3],
        );

        $heuristic->enrich($cluster, []);

        $params = $cluster->getParams();
        self::assertEqualsWithDelta(0.05, $params['density'], 1e-9);
        self::assertEqualsWithDelta(0.05, $heuristic->score($cluster), 1e-9);
        self::assertSame('density', $heuristic->weightKey());
    }
}
