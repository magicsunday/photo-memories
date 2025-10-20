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
use MagicSunday\Memories\Service\Clusterer\Scoring\LocationClusterScoreHeuristic;
use MagicSunday\Memories\Service\Feed\FeedUserPreferences;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class LocationClusterScoreHeuristicTest extends TestCase
{
    #[Test]
    public function enrichEvaluatesGeoCoverage(): void
    {
        $heuristic = new LocationClusterScoreHeuristic();

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2, 3],
        );

        $mediaMap = [
            1 => $this->makeMedia(id: 1, path: __DIR__ . '/location-1.jpg', lat: 52.5, lon: 13.4),
            2 => $this->makeMedia(id: 2, path: __DIR__ . '/location-2.jpg', lat: 52.5, lon: 13.4),
            3 => $this->makeMedia(id: 3, path: __DIR__ . '/location-3.jpg'),
        ];

        $heuristic->enrich($cluster, $mediaMap);

        $params = $cluster->getParams();
        self::assertEqualsWithDelta(2 / 3, $params['location_geo_coverage'], 1e-9);
        self::assertGreaterThan(0.0, $heuristic->score($cluster));
        self::assertSame('location', $heuristic->weightKey());
    }

    #[Test]
    public function multipliesScoreForFavouritePlacesFromPreferences(): void
    {
        $heuristic = new LocationClusterScoreHeuristic(1.5);

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'place'      => 'Berlin',
                'time_range' => ['from' => 1_700_000_000, 'to' => 1_700_000_400],
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [1, 2],
        );

        $mediaMap = [
            1 => $this->makeMedia(id: 1, path: __DIR__ . '/location-boost-1.jpg', lat: 52.5, lon: 13.4),
            2 => $this->makeMedia(id: 2, path: __DIR__ . '/location-boost-2.jpg', lat: 52.6, lon: 13.45),
        ];

        $preferences = new FeedUserPreferences(
            'user',
            'default',
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            ['Berlin'],
        );

        $heuristic->setFeedUserPreferences($preferences);
        $heuristic->prepare([$cluster], $mediaMap);
        $heuristic->enrich($cluster, $mediaMap);

        $params = $cluster->getParams();
        self::assertTrue($params['location_favourite_match']);
        self::assertGreaterThan(0.0, $params['location_score']);
        self::assertEqualsWithDelta($params['location_score'], $heuristic->score($cluster), 1e-9);
    }
}
