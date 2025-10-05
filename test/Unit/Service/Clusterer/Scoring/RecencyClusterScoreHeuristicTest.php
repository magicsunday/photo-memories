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
use MagicSunday\Memories\Service\Clusterer\Scoring\RecencyClusterScoreHeuristic;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RecencyClusterScoreHeuristicTest extends TestCase
{
    #[Test]
    public function enrichCalculatesRecencyRelativeToNow(): void
    {
        $now       = (new DateTimeImmutable('2024-02-01 00:00:00'))->getTimestamp();
        $heuristic = new RecencyClusterScoreHeuristic(
            timeRangeMinSamples: 3,
            timeRangeMinCoverage: 0.6,
            minValidYear: 1990,
            timeProvider: static fn (): int => $now,
        );

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-01-01 00:00:00'))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-01-02 00:00:00'))->getTimestamp(),
                ],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [],
        );

        $heuristic->prepare([], []);
        $heuristic->enrich($cluster, []);

        $params   = $cluster->getParams();
        $expected = 1.0 - (30.0 / 365.0);
        self::assertEqualsWithDelta($expected, $params['recency'], 1e-6);
        self::assertEqualsWithDelta($expected, $heuristic->score($cluster), 1e-6);
        self::assertSame('recency', $heuristic->weightKey());
    }
}
