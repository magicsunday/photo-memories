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
use MagicSunday\Memories\Service\Clusterer\Scoring\PoiClusterScoreHeuristic;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PoiClusterScoreHeuristicTest extends TestCase
{
    #[Test]
    public function enrichBuildsScoreFromMetadata(): void
    {
        $heuristic = new PoiClusterScoreHeuristic(['tourism/*' => 0.1]);

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'poi_label' => 'Brandenburger Tor',
                'poi_category_key' => 'tourism',
                'poi_tags' => ['wikidata' => 'Q64'],
            ],
            centroid: ['lat' => 52.5163, 'lon' => 13.3777],
            members: [],
        );

        $heuristic->enrich($cluster, []);

        $params = $cluster->getParams();
        self::assertEqualsWithDelta(0.95, $params['poi_score'], 1e-9);
        self::assertEqualsWithDelta(0.95, $heuristic->score($cluster), 1e-9);
        self::assertSame('poi', $heuristic->weightKey());
    }
}
