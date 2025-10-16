<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Pipeline;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Pipeline\CanonicalTitleStage;
use MagicSunday\Memories\Service\Clusterer\Title\RouteSummarizer;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function strtotime;

final class CanonicalTitleStageTest extends TestCase
{
    #[Test]
    public function annotatesVacationDraftWithCanonicalRoute(): void
    {
        $stage = new CanonicalTitleStage(new RouteSummarizer());

        $from = strtotime('2024-05-10T00:00:00+00:00');
        $to   = strtotime('2024-05-15T00:00:00+00:00');

        $vacation = new ClusterDraft(
            algorithm: 'vacation',
            params: [
                'score'                          => 0.95,
                'classification'                 => 'vacation',
                'classification_label'           => 'Urlaub',
                'away_days'                      => 5,
                'time_range'                     => ['from' => $from, 'to' => $to],
                'countries'                      => ['Spanien', 'Frankreich'],
                'total_travel_km'                => 845.6,
                'travel_waypoints'               => [
                    [
                        'label'         => 'Barcelona',
                        'count'         => 3,
                        'first_seen_at' => $from,
                        'lat'           => 41.3851,
                        'lon'           => 2.1734,
                    ],
                    [
                        'label'         => 'Valencia',
                        'count'         => 2,
                        'first_seen_at' => $from + 86400,
                        'lat'           => 39.4699,
                        'lon'           => -0.3763,
                    ],
                    [
                        'label'         => 'Alicante',
                        'count'         => 2,
                        'first_seen_at' => $from + (2 * 86400),
                        'lat'           => 38.3460,
                        'lon'           => -0.4907,
                    ],
                ],
                'member_quality' => [
                    'summary' => [
                        'selection_telemetry' => [
                            'staypoint_leaders' => [
                                ['label' => 'Barcelona'],
                                ['label' => 'Valencia'],
                                ['label' => 'Alicante'],
                            ],
                        ],
                    ],
                ],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3],
        );

        $motif = new ClusterDraft(
            algorithm: 'photo_motif',
            params: ['score' => 0.6],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [10, 11],
        );

        $result = $stage->process([
            $vacation,
            $motif,
        ]);

        $vacationParams = $result[0]->getParams();
        self::assertSame('Barcelona → Valencia → Alicante', $vacationParams['canonical_title']);
        self::assertSame('Urlaub • 5 Tage • 10.05. – 15.05.2024 • ca. 430 km • 2 Etappen • 3 Stopps', $vacationParams['canonical_subtitle']);

        self::assertArrayNotHasKey('canonical_title', $result[1]->getParams());
        self::assertArrayNotHasKey('canonical_subtitle', $result[1]->getParams());
    }

    #[Test]
    public function omitsTravelMetricsWhenNotAvailable(): void
    {
        $stage = new CanonicalTitleStage(new RouteSummarizer());

        $from = strtotime('2024-06-01T00:00:00+00:00');
        $to   = strtotime('2024-06-10T00:00:00+00:00');

        $vacation = new ClusterDraft(
            algorithm: 'vacation',
            params: [
                'classification_label'           => 'Urlaub',
                'away_days'                      => 10,
                'time_range'                     => ['from' => $from, 'to' => $to],
                'primaryStaypointLocationParts'  => ['Berlin'],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3],
        );

        $result = $stage->process([$vacation]);

        $vacationParams = $result[0]->getParams();
        self::assertSame('Berlin', $vacationParams['canonical_title']);
        self::assertSame('Urlaub • 10 Tage • 01.06. – 10.06.2024', $vacationParams['canonical_subtitle']);
    }

    #[Test]
    public function fallsBackToSummaryStaypointsWhenRouteIsMissing(): void
    {
        $stage = new CanonicalTitleStage(new RouteSummarizer());

        $from = strtotime('2024-04-01T00:00:00+00:00');
        $to   = strtotime('2024-04-05T00:00:00+00:00');

        $vacation = new ClusterDraft(
            algorithm: 'vacation',
            params: [
                'classification_label' => 'Urlaub',
                'away_days'            => 4,
                'time_range'           => ['from' => $from, 'to' => $to],
                'member_quality'       => [
                    'summary' => [
                        'selection_telemetry' => [
                            'staypoint_leaders' => ['Hamburg', 'Kopenhagen'],
                        ],
                    ],
                ],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [10, 11, 12],
        );

        $result = $stage->process([$vacation]);

        $params = $result[0]->getParams();
        self::assertSame('Hamburg → Kopenhagen', $params['canonical_title']);
        self::assertSame('Urlaub • 4 Tage • 01.04. – 05.04.2024 • 1 Etappe', $params['canonical_subtitle']);
    }
}
