<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidationStageInterface;

use function array_values;
use function count;

/**
 * Removes exact duplicates based on normalized member fingerprints.
 */
final class DuplicateCollapseStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;

    /** @var array<string,int> */
    private array $priorityMap = [];

    /**
     * @param list<string> $keepOrder
     */
    public function __construct(private readonly array $keepOrder)
    {
        $base = count($keepOrder);
        for ($index = 0; $index < $base; ++$index) {
            $this->priorityMap[$keepOrder[$index]] = $base - $index;
        }
    }

    public function getLabel(): string
    {
        return 'Exakte Duplikate';
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterDraft>
     */
    public function process(array $drafts, ?callable $progress = null): array
    {
        $total = count($drafts);
        if ($total <= 1) {
            if ($progress !== null) {
                $progress($total, $total);
            }

            return $drafts;
        }

        if ($progress !== null) {
            $progress(0, $total);
        }

        /** @var list<list<int>> $normalized */
        $normalized = [];
        foreach ($drafts as $draft) {
            $normalized[] = $this->normalizeMembers($draft->getMembers());
        }

        /** @var array<string,int> $winnerByFingerprint */
        $winnerByFingerprint = [];
        foreach ($drafts as $index => $draft) {
            if ($progress !== null && ($index % 400) === 0) {
                $progress($index, $total);
            }

            $fingerprint = $this->fingerprint($normalized[$index]);
            $current     = $winnerByFingerprint[$fingerprint] ?? null;
            if ($current === null) {
                $winnerByFingerprint[$fingerprint] = $index;
                continue;
            }

            if ($this->isBetter($draft, $normalized[$index], $drafts[$current], $normalized[$current])) {
                $winnerByFingerprint[$fingerprint] = $index;
            }
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        /** @var list<ClusterDraft> $result */
        $result = [];
        foreach (array_values($winnerByFingerprint) as $idx) {
            $result[] = $drafts[$idx];
        }

        return $result;
    }

    /**
     * @param list<int> $normA
     * @param list<int> $normB
     */
    private function isBetter(ClusterDraft $a, array $normA, ClusterDraft $b, array $normB): bool
    {
        $scoreA = $this->computeScore($a, $normA);
        $scoreB = $this->computeScore($b, $normB);
        if ($scoreA !== $scoreB) {
            return $scoreA > $scoreB;
        }

        $priorityA = (int) ($this->priorityMap[$a->getAlgorithm()] ?? 0);
        $priorityB = (int) ($this->priorityMap[$b->getAlgorithm()] ?? 0);
        if ($priorityA !== $priorityB) {
            return $priorityA > $priorityB;
        }

        return count($normA) >= count($normB);
    }
}
