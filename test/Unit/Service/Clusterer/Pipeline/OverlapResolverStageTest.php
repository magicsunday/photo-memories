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
use MagicSunday\Memories\Service\Clusterer\Pipeline\OverlapResolverStage;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\RecordingMonitoringEmitter;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OverlapResolverStageTest extends TestCase
{
    #[Test]
    public function dedupesLowerPriorityWithinSameAlgorithmAndLogsDecision(): void
    {
        $stage = new OverlapResolverStage(0.45, 0.85, ['vacation', 'hike_adventure']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4], 0.92, 'vacation');
        $dayTrip  = $this->createDraft('vacation', [1, 2, 3], 0.6, 'day_trip');
        $hike     = $this->createDraft('hike_adventure', [5, 6, 7], 0.7, null);
        $city     = $this->createDraft('significant_place', [8, 9], 0.5, null);

        $result = $stage->process([
            $vacation,
            $dayTrip,
            $hike,
            $city,
        ]);

        self::assertCount(3, $result);
        $kept = $result[0];
        self::assertSame('vacation', $kept->getAlgorithm());
        $merges = $kept->getParams()['meta']['merges'] ?? [];
        self::assertNotSame([], $merges);
        self::assertSame('dedupe', $merges[0]['decision']);
    }

    #[Test]
    public function dropsLowerPriorityOnSevereCrossAlgorithmOverlap(): void
    {
        $stage = new OverlapResolverStage(0.45, 0.85, ['vacation', 'hike_adventure']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4, 5, 6], 0.9, 'vacation');
        $hike     = $this->createDraft('hike_adventure', [1, 2, 3, 4, 5, 6, 7], 0.7, null);

        $result = $stage->process([
            $hike,
            $vacation,
        ]);

        self::assertCount(1, $result);
        $kept = $result[0];
        self::assertSame('vacation', $kept->getAlgorithm());
        $merges = $kept->getParams()['meta']['merges'] ?? [];
        self::assertNotSame([], $merges);
        self::assertSame('dedupe', $merges[0]['decision']);
    }

    #[Test]
    public function ignoresSubStoriesDuringOverlapResolution(): void
    {
        $stage = new OverlapResolverStage(0.45, 0.85, ['vacation', 'significant_place']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4], 0.92, 'vacation');
        $chapter  = $this->createDraft('significant_place', [1, 2, 3], 0.6, null);
        $chapter->setParam('is_sub_story', true);
        $chapter->setParam('sub_story_priority', 1);
        $chapter->setParam('sub_story_of', ['algorithm' => 'vacation', 'fingerprint' => sha1('1,2,3,4'), 'priority' => 2]);

        $result = $stage->process([
            $vacation,
            $chapter,
        ]);

        self::assertSame([
            $vacation,
            $chapter,
        ], $result);
    }

    #[Test]
    public function mergesCompatibleDraftsAndExtendsMetadata(): void
    {
        $stage = new OverlapResolverStage(0.45, 0.85, ['vacation', 'hike_adventure']);

        $primary = $this->createDraft(
            'vacation',
            [10, 11, 12],
            0.82,
            'vacation',
            [
                'time_range' => ['from' => 1_000, 'to' => 3_600],
                'primaryStaypoint' => ['lat' => 48.13, 'lon' => 11.58],
                'member_selection' => ['hash_samples' => ['abcd1234abcd1234', 'abcd1234ffff0000']],
                'meta' => ['merges' => []],
            ],
        );

        $secondary = $this->createDraft(
            'vacation',
            [11, 12, 13],
            0.8,
            'vacation',
            [
                'time_range' => ['from' => 1_600, 'to' => 3_800],
                'primaryStaypoint' => ['lat' => 48.1301, 'lon' => 11.5802],
                'member_selection' => ['hash_samples' => ['abcd1234aaaa0000']],
            ],
        );

        $result = $stage->process([
            $primary,
            $secondary,
        ]);

        self::assertCount(1, $result);
        $merged = $result[0];
        self::assertSame([10, 11, 12, 13], $merged->getMembers());

        $params = $merged->getParams();
        self::assertSame(1_000, $params['time_range']['from']);
        self::assertSame(3_800, $params['time_range']['to']);
        self::assertArrayHasKey('member_selection', $params);
        self::assertCount(3, $params['member_selection']['hash_samples']);

        $merges = $params['meta']['merges'] ?? [];
        self::assertNotSame([], $merges);
        $entry = $merges[0];
        self::assertSame('merge', $entry['decision']);
        self::assertSame('winner', $entry['role']);
    }

    #[Test]
    public function emitsTelemetryForOverlapResolution(): void
    {
        $emitter = new RecordingMonitoringEmitter();
        $stage   = new OverlapResolverStage(0.45, 0.85, ['vacation', 'hike_adventure'], $emitter);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4], 0.92, 'vacation');
        $dayTrip  = $this->createDraft('vacation', [1, 2, 3], 0.6, 'day_trip');
        $hike     = $this->createDraft('hike_adventure', [5, 6, 7], 0.7, null);
        $city     = $this->createDraft('significant_place', [8, 9], 0.5, null);

        $stage->process([
            $vacation,
            $dayTrip,
            $hike,
            $city,
        ]);

        self::assertCount(2, $emitter->events);
        $start = $emitter->events[0];
        self::assertSame('overlap_resolver', $start['job']);
        self::assertSame('selection_start', $start['status']);
        self::assertSame(4, $start['context']['pre_count']);

        $completed = $emitter->events[1];
        self::assertSame('overlap_resolver', $completed['job']);
        self::assertSame('selection_completed', $completed['status']);
        self::assertSame(4, $completed['context']['pre_count']);
        self::assertSame(3, $completed['context']['post_count']);
        self::assertSame(1, $completed['context']['dropped_count']);
        self::assertSame(1, $completed['context']['resolved_drops']);
    }

    #[Test]
    public function keepsCrossAlgorithmOverlapBelowDropThreshold(): void
    {
        $stage = new OverlapResolverStage(0.45, 0.85, ['vacation', 'hike_adventure']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4, 5], 0.92, 'vacation');
        $hike     = $this->createDraft('hike_adventure', [1, 2, 3, 4, 6], 0.8, null);

        $result = $stage->process([
            $vacation,
            $hike,
        ]);

        self::assertSame([
            $vacation,
            $hike,
        ], $result);
    }

    /**
     * @param list<int> $members
     */
    private function createDraft(
        string $algorithm,
        array $members,
        float $score,
        ?string $classification,
        array $extraParams = [],
    ): ClusterDraft {
        $params = ['score' => $score];
        if ($classification !== null) {
            $params['classification'] = $classification;
        }

        $params = array_merge($params, $extraParams);

        return new ClusterDraft(
            algorithm: $algorithm,
            params: $params,
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: $members,
        );
    }
}
