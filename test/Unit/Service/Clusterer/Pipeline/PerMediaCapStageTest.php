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
