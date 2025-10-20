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
use MagicSunday\Memories\Entity\Media;
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
                'poi_label'        => 'Brandenburger Tor',
                'poi_category_key' => 'tourism',
                'poi_tags'         => ['wikidata' => 'Q64'],
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

    #[Test]
    public function enrichUsesPersistedPoiScore(): void
    {
        $heuristic = new PoiClusterScoreHeuristic(['tourism/*' => 0.1]);

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'poi_score' => 0.42,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: [],
        );

        $heuristic->prepare([], []);
        $heuristic->enrich($cluster, []);

        $params = $cluster->getParams();

        self::assertEqualsWithDelta(0.42, $params['poi_score'], 1e-9);
        self::assertEqualsWithDelta(0.42, $heuristic->score($cluster), 1e-9);
    }

    #[Test]
    public function enrichAppliesIconicBoostAndTelemetry(): void
    {
        $iconicHash = 'ABCDEF12ABCDEF12';

        $heuristic = new PoiClusterScoreHeuristic(
            ['tourism/*' => 0.1],
            0.12,
            0.7,
            ['brandenburg_gate' => $iconicHash],
        );

        $members = [101, 102, 103, 104, 105];

        $cluster = new ClusterDraft(
            algorithm: 'test',
            params: [
                'poi_label'        => 'Brandenburger Tor',
                'poi_category_key' => 'tourism',
                'poi_tags'         => ['wikidata' => 'Q64'],
            ],
            centroid: ['lat' => 52.5163, 'lon' => 13.3777],
            members: $members,
        );

        $mediaMap = [];
        foreach ($members as $index => $id) {
            $mediaMap[$id] = $this->makeMedia(
                id: $id,
                path: __DIR__ . sprintf('/fixtures/%d.jpg', $id),
                configure: static function (Media $media) use ($index, $iconicHash): void {
                    if ($index < 4) {
                        $media->setPhash($iconicHash);
                    } else {
                        $media->setPhash('ffff1111ffff1111');
                    }
                },
            );
        }

        $heuristic->enrich($cluster, $mediaMap);

        $params = $cluster->getParams();

        self::assertEqualsWithDelta(1.0, $params['poi_score'], 1e-9);
        self::assertEqualsWithDelta(1.0, $heuristic->score($cluster), 1e-9);
        self::assertTrue($params['poi_iconic_boost_applied']);
        self::assertTrue($params['poi_iconic_is_dominant']);
        self::assertSame('signature', $params['poi_iconic_trigger']);
        self::assertSame('brandenburg_gate', $params['poi_iconic_signature']);
        self::assertEqualsWithDelta(1.0, $params['poi_iconic_signature_similarity'], 1e-9);
        self::assertSame('abcdef12abcdef12', $params['poi_iconic_phash']);
        self::assertSame(4, $params['poi_iconic_count']);
        self::assertSame(5, $params['poi_iconic_sample_count']);
        self::assertEqualsWithDelta(0.8, $params['poi_iconic_ratio'], 1e-9);

        $heuristic->enrich($cluster, $mediaMap);

        $paramsAfterSecondRun = $cluster->getParams();
        self::assertEqualsWithDelta(1.0, $paramsAfterSecondRun['poi_score'], 1e-9);
        self::assertTrue($paramsAfterSecondRun['poi_iconic_boost_applied']);
    }
}
