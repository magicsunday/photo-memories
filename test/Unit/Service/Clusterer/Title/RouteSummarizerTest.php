<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Title;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Title\RouteSummarizer;
use MagicSunday\Memories\Service\Clusterer\Title\RouteSummary;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RouteSummarizer::class)]
#[CoversClass(RouteSummary::class)]
#[CoversClass(ClusterDraft::class)]
final class RouteSummarizerTest extends TestCase
{
    #[Test]
    public function summarisesDominantWaypoints(): void
    {
        $summarizer = new RouteSummarizer();

        $cluster = new ClusterDraft(
            algorithm: 'vacation',
            params: [
                'time_range' => [
                    'from' => 0,
                    'to'   => 0,
                ],
                'travel_waypoints' => [
                    [
                        'label'         => 'Alpha',
                        'city'          => 'Alpha',
                        'region'        => null,
                        'country'       => null,
                        'countryCode'   => null,
                        'count'         => 6,
                        'first_seen_at' => 100,
                        'lat'           => 0.0,
                        'lon'           => 0.0,
                    ],
                    [
                        'label'         => 'Beta',
                        'city'          => 'Beta',
                        'region'        => null,
                        'country'       => null,
                        'countryCode'   => null,
                        'count'         => 4,
                        'first_seen_at' => 200,
                        'lat'           => 0.0,
                        'lon'           => 1.0,
                    ],
                    [
                        'label'         => 'Gamma',
                        'city'          => 'Gamma',
                        'region'        => null,
                        'country'       => null,
                        'countryCode'   => null,
                        'count'         => 3,
                        'first_seen_at' => 300,
                        'lat'           => 1.0,
                        'lon'           => 1.0,
                    ],
                    [
                        'label'         => 'Alpha',
                        'city'          => 'Alpha',
                        'region'        => null,
                        'country'       => null,
                        'countryCode'   => null,
                        'count'         => 1,
                        'first_seen_at' => 400,
                        'lat'           => 0.0,
                        'lon'           => 0.0,
                    ],
                ],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [],
        );

        $summary = $summarizer->summarize($cluster);

        self::assertNotNull($summary);
        self::assertSame('Alpha → Beta → Gamma', $summary->routeLabel);
        self::assertSame(3, $summary->stopCount);
        self::assertSame(2, $summary->legCount);
        self::assertSame('ca. 220 km', $summary->distanceLabel);
        self::assertSame('3 Stopps', $summary->stopLabel);
        self::assertSame('ca. 220 km • 3 Stopps', $summary->metricsLabel);
    }

    #[Test]
    public function returnsNullWhenNotEnoughStops(): void
    {
        $summarizer = new RouteSummarizer();

        $cluster = new ClusterDraft(
            algorithm: 'vacation',
            params: [
                'travel_waypoints' => [
                    [
                        'label'         => 'Alpha',
                        'city'          => 'Alpha',
                        'region'        => null,
                        'country'       => null,
                        'countryCode'   => null,
                        'count'         => 2,
                        'first_seen_at' => 100,
                        'lat'           => 0.0,
                        'lon'           => 0.0,
                    ],
                    [
                        'label'         => 'Beta',
                        'city'          => 'Beta',
                        'region'        => null,
                        'country'       => null,
                        'countryCode'   => null,
                        'count'         => 1,
                        'first_seen_at' => 200,
                        'lat'           => 0.0,
                        'lon'           => 1.0,
                    ],
                ],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [],
        );

        self::assertNull($summarizer->summarize($cluster));
    }
}
