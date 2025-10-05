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
use MagicSunday\Memories\Service\Clusterer\Scoring\HolidayClusterScoreHeuristic;
use MagicSunday\Memories\Service\Clusterer\Scoring\HolidayResolverInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class HolidayClusterScoreHeuristicTest extends TestCase
{
    #[Test]
    public function enrichMarksHolidayRange(): void
    {
        $resolver = new class implements HolidayResolverInterface {
            public function isHoliday(DateTimeImmutable $day): bool
            {
                return $day->format('Y-m-d') === '2024-12-25';
            }
        };

        $heuristic = new HolidayClusterScoreHeuristic(
            holidayResolver: $resolver,
            timeRangeMinSamples: 3,
            timeRangeMinCoverage: 0.6,
            minValidYear: 1990,
        );

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-12-25 08:00:00'))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-12-25 18:00:00'))->getTimestamp(),
                ],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [],
        );

        $heuristic->enrich($cluster, []);

        $params = $cluster->getParams();
        self::assertEqualsWithDelta(1.0, $params['holiday'], 1e-9);
        self::assertEqualsWithDelta(1.0, $heuristic->score($cluster), 1e-9);
        self::assertSame('holiday', $heuristic->weightKey());
    }
}
