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
use MagicSunday\Memories\Service\Clusterer\Pipeline\DominanceSelectionStage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DominanceSelectionStageTest extends TestCase
{
    #[Test]
    public function prefersPriorityAlgorithmsAndSuppressesOverlap(): void
    {
        $stage = new DominanceSelectionStage(
            overlapMergeThreshold: 0.5,
            overlapDropThreshold: 0.9,
            keepOrder: ['primary', 'secondary'],
        );

        $primaryWinner    = $this->createDraft('primary', 0.8, [1, 2, 3]);
        $secondaryOverlap = $this->createDraft('secondary', 0.95, [2, 3, 4]);
        $tertiary         = $this->createDraft('tertiary', 0.7, [10, 11]);
        $secondaryUnique  = $this->createDraft('secondary', 0.6, [5, 6, 7]);

        $result = $stage->process([
            $primaryWinner,
            $secondaryOverlap,
            $tertiary,
            $secondaryUnique,
        ]);

        self::assertSame([
            $primaryWinner,
            $secondaryUnique,
            $tertiary,
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
