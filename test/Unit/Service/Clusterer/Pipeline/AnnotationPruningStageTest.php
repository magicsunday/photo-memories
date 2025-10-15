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
use MagicSunday\Memories\Service\Clusterer\Pipeline\AnnotationPruningStage;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\RecordingMonitoringEmitter;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AnnotationPruningStageTest extends TestCase
{
    #[Test]
    public function keepsAnnotationWhenUniqueShareIsHighEnough(): void
    {
        $stage = new AnnotationPruningStage(['annot'], ['annot' => 0.5]);

        $baseCluster = $this->createDraft('primary', 0.9, [1, 2, 3]);
        $annotKeeps  = $this->createDraft('annot', 0.7, [3, 4]);
        $annotDrops  = $this->createDraft('annot', 0.8, [1]);

        $result = $stage->process([
            $baseCluster,
            $annotKeeps,
            $annotDrops,
        ]);

        self::assertSame([
            $baseCluster,
            $annotKeeps,
        ], $result);
    }

    #[Test]
    public function emitsTelemetryForAnnotationFiltering(): void
    {
        $emitter = new RecordingMonitoringEmitter();
        $stage   = new AnnotationPruningStage(['annot'], ['annot' => 0.5], $emitter);

        $baseCluster = $this->createDraft('primary', 0.9, [1, 2, 3]);
        $annotKeeps  = $this->createDraft('annot', 0.7, [3, 4]);
        $annotDrops  = $this->createDraft('annot', 0.8, [1]);

        $stage->process([
            $baseCluster,
            $annotKeeps,
            $annotDrops,
        ]);

        self::assertCount(2, $emitter->events);

        $start = $emitter->events[0];
        self::assertSame('annotation_pruning', $start['job']);
        self::assertSame('selection_start', $start['status']);
        self::assertSame(3, $start['context']['pre_count']);
        self::assertSame(2, $start['context']['annotate_candidates']);
        self::assertSame(['annot'], $start['context']['annotate_algorithms']);

        $completed = $emitter->events[1];
        self::assertSame('annotation_pruning', $completed['job']);
        self::assertSame('selection_completed', $completed['status']);
        self::assertSame(3, $completed['context']['pre_count']);
        self::assertSame(2, $completed['context']['post_count']);
        self::assertSame(1, $completed['context']['dropped_annotations']);
        self::assertSame(1, $completed['context']['kept_annotations']);
        self::assertSame(1, $completed['context']['dropped_count']);
    }

    /**
     * @param list<int> $members
     */
    private function createDraft(string $algorithm, float $score, array $members): ClusterDraft
    {
        return new ClusterDraft(
            algorithm: $algorithm,
            params: ['score' => $score],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: $members,
        );
    }
}
