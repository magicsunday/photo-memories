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
use MagicSunday\Memories\Service\Clusterer\Pipeline\DominanceSelectionStage;
use MagicSunday\Memories\Service\Clusterer\Pipeline\DuplicateCollapseStage;
use MagicSunday\Memories\Service\Clusterer\Pipeline\FilterNormalizationStage;
use MagicSunday\Memories\Service\Clusterer\Pipeline\NestingResolverStage;
use MagicSunday\Memories\Service\Clusterer\Pipeline\OverlapResolverStage;
use MagicSunday\Memories\Service\Clusterer\Pipeline\PerMediaCapStage;
use MagicSunday\Memories\Service\Clusterer\Pipeline\PipelineClusterConsolidator;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PipelineClusterConsolidatorTest extends TestCase
{
    #[Test]
    public function consolidatesDraftsAcrossAllStages(): void
    {
        $pipeline = new PipelineClusterConsolidator([
            new FilterNormalizationStage(
                minScore: 0.5,
                minSize: 2,
                requireValidTime: false,
                minValidYear: 1990,
            ),
            new DuplicateCollapseStage(['primary', 'secondary']),
            new DominanceSelectionStage(
                overlapMergeThreshold: 0.45,
                overlapDropThreshold: 0.85,
                keepOrder: ['primary', 'secondary'],
                classificationPriority: [],
            ),
            new AnnotationPruningStage(['annot'], ['annot' => 0.4]),
            new PerMediaCapStage(
                perMediaCap: 1,
                keepOrder: ['primary', 'secondary'],
                algorithmGroups: [
                    'primary'   => 'stories',
                    'secondary' => 'stories',
                    'annot'     => 'annotations',
                ],
                defaultAlgorithmGroup: 'default',
            ),
        ]);

        $primaryWinner         = $this->createDraft('primary', 0.9, [1, 2, 3]);
        $primaryDuplicate      = $this->createDraft('primary', 0.6, [3, 2, 1]);
        $secondaryDominated    = $this->createDraft('secondary', 0.95, [2, 3, 4]);
        $secondaryCapCandidate = $this->createDraft('secondary', 0.8, [3, 6]);
        $annotationKeeps       = $this->createDraft('annot', 0.7, [4, 5, 6]);
        $annotationDrops       = $this->createDraft('annot', 0.9, [1, 3]);
        $tooSmall              = $this->createDraft('primary', 0.9, [10]);
        $tooLowScore           = $this->createDraft('primary', 0.3, [11, 12]);

        $result = $pipeline->consolidate([
            $primaryWinner,
            $primaryDuplicate,
            $secondaryDominated,
            $secondaryCapCandidate,
            $annotationKeeps,
            $annotationDrops,
            $tooSmall,
            $tooLowScore,
        ]);

        self::assertSame([
            $primaryWinner,
            $annotationKeeps,
        ], $result);
    }

    #[Test]
    public function testNestingResolverRunsBeforeOverlapResolver(): void
    {
        $pipeline = new PipelineClusterConsolidator([
            new NestingResolverStage(['vacation']),
            new OverlapResolverStage(0.45, 0.85, ['vacation']),
        ]);

        $parent = $this->createDraft('vacation', 0.9, [1, 2, 3, 4]);
        $child  = $this->createDraft('vacation', 0.7, [1, 2, 3]);

        $result = $pipeline->consolidate([
            $parent,
            $child,
        ]);

        self::assertSame([
            $parent,
            $child,
        ], $result);

        $childParams = $child->getParams();
        self::assertTrue($childParams['is_sub_story'] ?? false, 'Child cluster must be marked as sub story.');

        $overlapFirst = new PipelineClusterConsolidator([
            new OverlapResolverStage(0.45, 0.85, ['vacation']),
            new NestingResolverStage(['vacation']),
        ]);

        $overlapParent = $this->createDraft('vacation', 0.9, [1, 2, 3, 4]);
        $overlapChild  = $this->createDraft('vacation', 0.7, [1, 2, 3]);

        $overlapResult = $overlapFirst->consolidate([
            $overlapParent,
            $overlapChild,
        ]);

        self::assertSame([
            $overlapParent,
        ], $overlapResult, 'Overlap resolver must drop nested child when metadata is missing.');
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
