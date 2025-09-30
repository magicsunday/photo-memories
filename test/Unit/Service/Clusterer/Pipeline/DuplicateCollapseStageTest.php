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
use MagicSunday\Memories\Service\Clusterer\Pipeline\DuplicateCollapseStage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DuplicateCollapseStageTest extends TestCase
{
    #[Test]
    public function keepsBestRepresentativePerFingerprint(): void
    {
        $stage = new DuplicateCollapseStage(['primary', 'secondary']);

        $primaryWinner   = $this->createDraft('primary', 0.8, [1, 2, 3]);
        $primaryLower    = $this->createDraft('primary', 0.5, [3, 1, 2]);
        $secondaryHigher = $this->createDraft('secondary', 0.9, [1, 2, 3]);
        $secondaryEqual  = $this->createDraft('secondary', 0.9, [3, 2, 1]);
        $unrelated       = $this->createDraft('primary', 0.7, [4, 5]);

        $result = $stage->process([
            $primaryWinner,
            $primaryLower,
            $secondaryHigher,
            $secondaryEqual,
            $unrelated,
        ]);

        self::assertCount(2, $result);
        self::assertTrue(in_array($unrelated, $result, true));
        self::assertTrue(
            in_array($secondaryHigher, $result, true)
            || in_array($secondaryEqual, $result, true),
        );
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
