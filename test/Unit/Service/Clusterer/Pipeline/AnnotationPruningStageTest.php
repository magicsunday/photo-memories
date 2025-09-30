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
