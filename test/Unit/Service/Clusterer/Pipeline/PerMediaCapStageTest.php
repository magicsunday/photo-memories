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
use MagicSunday\Memories\Service\Clusterer\Pipeline\PerMediaCapStage;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\RecordingMonitoringEmitter;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PerMediaCapStageTest extends TestCase
{
    #[Test]
    public function appliesCapPerGroupAndKeepsOtherGroups(): void
    {
        $stage = new PerMediaCapStage(
            perMediaCap: 1,
            keepOrder: ['primary', 'secondary'],
            algorithmGroups: [
                'primary'   => 'stories',
                'secondary' => 'stories',
                'annot'     => 'annotations',
            ],
            defaultAlgorithmGroup: 'default',
        );

        $primary    = $this->createDraft('primary', 0.9, [1, 2]);
        $secondary  = $this->createDraft('secondary', 0.8, [2, 3]);
        $annotation = $this->createDraft('annot', 0.7, [2, 4]);

        $result = $stage->process([
            $primary,
            $secondary,
            $annotation,
        ]);

        self::assertSame([
            $primary,
            $annotation,
        ], $result);
    }

    #[Test]
    public function keepsSubStoriesEvenWhenMembersExceedCap(): void
    {
        $stage = new PerMediaCapStage(
            perMediaCap: 1,
            keepOrder: ['primary', 'secondary'],
            algorithmGroups: [
                'primary'   => 'stories',
                'secondary' => 'stories',
            ],
            defaultAlgorithmGroup: 'default',
        );

        $primary  = $this->createDraft('primary', 0.9, [1, 2]);
        $subStory = $this->createDraft('secondary', 0.8, [1, 2]);
        $subStory->setParam('is_sub_story', true);
        $subStory->setParam('sub_story_priority', 1);
        $subStory->setParam('sub_story_of', ['algorithm' => 'primary', 'fingerprint' => sha1('1,2'), 'priority' => 2]);

        $result = $stage->process([
            $primary,
            $subStory,
        ]);

        self::assertSame([
            $primary,
            $subStory,
        ], $result);
    }

    #[Test]
    public function replacesLowerPriorityClusterToKeepCoverMedia(): void
    {
        $stage = new PerMediaCapStage(
            perMediaCap: 1,
            keepOrder: ['primary', 'secondary'],
            algorithmGroups: [
                'primary'   => 'stories',
                'secondary' => 'stories',
            ],
            defaultAlgorithmGroup: 'default',
        );

        $nonCover = $this->createDraft('secondary', 0.95, [1, 4], 4);
        $cover    = $this->createDraft('primary', 0.9, [1, 2], 1);

        $result = $stage->process([
            $nonCover,
            $cover,
        ]);

        self::assertSame([
            $cover,
        ], $result);
    }

    #[Test]
    public function emitsTelemetryForCapDecisions(): void
    {
        $emitter = new RecordingMonitoringEmitter();
        $stage   = new PerMediaCapStage(
            perMediaCap: 1,
            keepOrder: ['primary', 'secondary'],
            algorithmGroups: [
                'primary'   => 'stories',
                'secondary' => 'stories',
            ],
            defaultAlgorithmGroup: 'default',
            monitoringEmitter: $emitter,
        );

        $initial  = $this->createDraft('primary', 0.95, [1]);
        $cover    = $this->createDraft('secondary', 0.9, [1], 1);
        $blocked  = $this->createDraft('secondary', 0.7, [1]);

        $stage->process([
            $initial,
            $cover,
            $blocked,
        ]);

        self::assertCount(2, $emitter->events);

        $start = $emitter->events[0];
        self::assertSame('per_media_cap', $start['job']);
        self::assertSame('selection_start', $start['status']);
        self::assertSame(3, $start['context']['pre_count']);
        self::assertSame(1, $start['context']['per_media_cap']);

        $completed = $emitter->events[1];
        self::assertSame('per_media_cap', $completed['job']);
        self::assertSame('selection_completed', $completed['status']);
        self::assertSame(3, $completed['context']['pre_count']);
        self::assertSame(1, $completed['context']['post_count']);
        self::assertSame(2, $completed['context']['dropped_count']);
        self::assertSame(1, $completed['context']['blocked_candidates']);
        self::assertSame(1, $completed['context']['reassigned_slots']);
    }

    /**
     * @param list<int> $members
     */
    private function createDraft(string $algorithm, float $score, array $members, ?int $coverId = null): ClusterDraft
    {
        $draft = new ClusterDraft(
            algorithm: $algorithm,
            params: ['score' => $score],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: $members,
        );

        if ($coverId !== null) {
            $draft->setCoverMediaId($coverId);
        }

        return $draft;
    }
}
