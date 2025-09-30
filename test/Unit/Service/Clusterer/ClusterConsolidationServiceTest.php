<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\ClusterConsolidationService;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ClusterConsolidationServiceTest extends TestCase
{
    #[Test]
    public function consolidatesDraftsAcrossPipelineStages(): void
    {
        $service = new ClusterConsolidationService(
            minScore: 0.5,
            minSize: 2,
            overlapMergeThreshold: 0.5,
            overlapDropThreshold: 0.9,
            perMediaCap: 1,
            keepOrder: ['primary', 'secondary'],
            annotateOnly: ['annot'],
            minUniqueShare: ['annot' => 0.4],
            algorithmGroups: [
                'primary' => 'stories',
                'secondary' => 'stories',
                'annot' => 'annotations',
            ],
            requireValidTime: false,
            minValidYear: 1990,
        );

        $primaryWinner         = $this->createDraft('primary', 0.9, [1, 2, 3]);
        $primaryDuplicate      = $this->createDraft('primary', 0.6, [3, 2, 1]);
        $secondaryDominated    = $this->createDraft('secondary', 0.95, [2, 3, 4]);
        $secondaryCapCandidate = $this->createDraft('secondary', 0.8, [3, 6]);
        $annotationKeeps       = $this->createDraft('annot', 0.7, [4, 5, 6]);
        $annotationDrops       = $this->createDraft('annot', 0.9, [1, 3]);
        $tooSmall              = $this->createDraft('primary', 0.9, [10]);
        $tooLowScore           = $this->createDraft('primary', 0.3, [11, 12]);

        $result = $service->consolidate([
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
    public function allowsSameMediumAcrossGroupsWhileEnforcingGroupCap(): void
    {
        $service = new ClusterConsolidationService(
            minScore: 0.1,
            minSize: 1,
            overlapMergeThreshold: 0.5,
            overlapDropThreshold: 0.9,
            perMediaCap: 1,
            keepOrder: ['primary', 'secondary', 'other'],
            annotateOnly: [],
            minUniqueShare: [],
            algorithmGroups: [
                'primary' => 'group_a',
                'secondary' => 'group_b',
                'other' => 'group_b',
            ],
            requireValidTime: false,
            minValidYear: 1990,
        );

        $primary     = $this->createDraft('primary', 1.0, [1, 2]);
        $secondary   = $this->createDraft('secondary', 0.9, [1, 3]);
        $primaryDrop = $this->createDraft('primary', 0.8, [1, 4]);
        $otherDrop   = $this->createDraft('other', 0.7, [1, 5]);

        $result = $service->consolidate([
            $primary,
            $secondary,
            $primaryDrop,
            $otherDrop,
        ]);

        self::assertSame([
            $primary,
            $secondary,
        ], $result);
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
