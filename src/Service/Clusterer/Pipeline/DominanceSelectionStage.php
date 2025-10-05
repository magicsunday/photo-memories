<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidationStageInterface;

use function array_fill_keys;
use function array_keys;
use function array_map;
use function count;
use function usort;

/**
 * Applies algorithm dominance and overlap thresholds to select winners.
 */
final class DominanceSelectionStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;

    /** @var array<string,int> */
    private array $priorityMap = [];

    /**
     * @param list<string> $keepOrder
     */
    public function __construct(
        private readonly float $overlapMergeThreshold,
        private readonly float $overlapDropThreshold,
        private readonly array $keepOrder,
    ) {
        if ($this->overlapDropThreshold < $this->overlapMergeThreshold) {
            throw new InvalidArgumentException('overlapDropThreshold must be >= overlapMergeThreshold');
        }

        $base = count($keepOrder);
        foreach ($keepOrder as $index => $algorithm) {
            $this->priorityMap[$algorithm] = $base - $index;
        }
    }

    public function getLabel(): string
    {
        return 'Dominanz';
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
        $normalized = array_map(
            fn (ClusterDraft $draft): array => $this->normalizeMembers($draft->getMembers()),
            $drafts,
        );

        /** @var array<string,list<int>> $byAlgorithm */
        $byAlgorithm = [];
        foreach ($drafts as $index => $draft) {
            $algorithm = $draft->getAlgorithm();
            $byAlgorithm[$algorithm] ??= [];
            $byAlgorithm[$algorithm][] = $index;
        }

        /** @var list<string> $order */
        $order = $this->keepOrder;
        /** @var array<string,bool> $seen */
        $seen = array_fill_keys($order, true);

        foreach (array_keys($byAlgorithm) as $algorithm) {
            if (isset($seen[$algorithm])) {
                continue;
            }

            $order[]          = $algorithm;
            $seen[$algorithm] = true;
        }

        /** @var list<int> $selected */
        $selected  = [];
        $processed = 0;

        foreach ($order as $algorithm) {
            $indices = $byAlgorithm[$algorithm] ?? [];
            if ($indices === []) {
                continue;
            }

            usort($indices, function (int $a, int $b) use ($drafts, $normalized): int {
                $draftA = $drafts[$a];
                $draftB = $drafts[$b];
                $scoreA = $this->computeScore($draftA, $normalized[$a]);
                $scoreB = $this->computeScore($draftB, $normalized[$b]);
                if ($scoreA !== $scoreB) {
                    return $scoreA < $scoreB ? 1 : -1;
                }

                $priorityA = (int) ($this->priorityMap[$draftA->getAlgorithm()] ?? 0);
                $priorityB = (int) ($this->priorityMap[$draftB->getAlgorithm()] ?? 0);
                if ($priorityA !== $priorityB) {
                    return $priorityA < $priorityB ? 1 : -1;
                }

                $sizeA = count($normalized[$a]);
                $sizeB = count($normalized[$b]);
                if ($sizeA !== $sizeB) {
                    return $sizeA < $sizeB ? 1 : -1;
                }

                return 0;
            });

            foreach ($indices as $candidate) {
                ++$processed;
                if ($progress !== null && ($processed % 200) === 0) {
                    $progress($processed, $total);
                }

                $reject = false;
                foreach ($selected as $winner) {
                    $overlap = $this->jaccard($normalized[$candidate], $normalized[$winner]);
                    if ($overlap >= $this->overlapDropThreshold) {
                        $reject = true;
                        break;
                    }

                    if ($overlap >= $this->overlapMergeThreshold) {
                        $reject = true;
                        break;
                    }
                }

                if ($reject) {
                    continue;
                }

                $selected[] = $candidate;
            }
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        /** @var list<ClusterDraft> $result */
        $result = array_map(
            static fn (int $index): ClusterDraft => $drafts[$index],
            $selected,
        );

        return $result;
    }
}
