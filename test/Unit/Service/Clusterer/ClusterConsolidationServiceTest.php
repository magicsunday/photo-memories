<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\ClusterConsolidationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
            requireValidTime: false,
            minValidYear: 1990,
        );

        $primaryWinner = $this->createDraft('primary', 0.9, [1, 2, 3]);
        $primaryDuplicate = $this->createDraft('primary', 0.6, [3, 2, 1]);
        $secondaryDominated = $this->createDraft('secondary', 0.95, [2, 3, 4]);
        $secondaryCapCandidate = $this->createDraft('secondary', 0.8, [3, 6]);
        $annotationKeeps = $this->createDraft('annot', 0.7, [4, 5, 6]);
        $annotationDrops = $this->createDraft('annot', 0.9, [1, 3]);
        $tooSmall = $this->createDraft('primary', 0.9, [10]);
        $tooLowScore = $this->createDraft('primary', 0.3, [11, 12]);

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
