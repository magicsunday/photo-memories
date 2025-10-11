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

use function array_filter;
use function array_fill;
use function array_map;
use function array_values;
use function count;

use const ARRAY_FILTER_USE_BOTH;

/**
 * Removes lower priority clusters that are nested inside higher priority results.
 */
final class NestingResolverStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;
    use ClusterPriorityResolverTrait;

    /** @var array<string, int> */
    private array $priorityMap = [];

    /**
     * @param list<string> $keepOrder
     */
    public function __construct(array $keepOrder)
    {
        $base = count($keepOrder);
        foreach ($keepOrder as $index => $algorithm) {
            $this->priorityMap[$algorithm] = $base - $index;
        }
    }

    public function getLabel(): string
    {
        return 'Verschachtelung';
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

        /** @var list<bool> $keep */
        $keep = array_fill(0, $total, true);

        for ($i = 0; $i < $total; ++$i) {
            if (!$keep[$i]) {
                continue;
            }

            for ($j = $i + 1; $j < $total; ++$j) {
                if (!$keep[$j]) {
                    continue;
                }

                $normalizedLeft  = $normalized[$i];
                $normalizedRight = $normalized[$j];

                $leftInsideRight  = $this->isSubset($normalizedLeft, $normalizedRight);
                $rightInsideLeft  = $leftInsideRight ? false : $this->isSubset($normalizedRight, $normalizedLeft);

                if (!$leftInsideRight && !$rightInsideLeft) {
                    continue;
                }

                $preferLeft = $this->preferLeft(
                    $drafts[$i],
                    $normalizedLeft,
                    $drafts[$j],
                    $normalizedRight,
                    $this->priorityMap,
                );

                if ($preferLeft) {
                    $keep[$j] = false;
                } else {
                    $keep[$i] = false;
                    break;
                }
            }
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        /** @var list<ClusterDraft> $result */
        $result = array_values(array_filter(
            $drafts,
            static fn (ClusterDraft $draft, int $index) => $keep[$index] ?? false,
            ARRAY_FILTER_USE_BOTH,
        ));

        return $result;
    }

    /**
     * @param list<int> $subset
     * @param list<int> $superset
     */
    private function isSubset(array $subset, array $superset): bool
    {
        $subsetCount   = count($subset);
        $supersetCount = count($superset);
        if ($subsetCount === 0 || $subsetCount > $supersetCount) {
            return false;
        }

        $i = 0;
        $j = 0;

        while ($i < $subsetCount && $j < $supersetCount) {
            $subsetValue   = $subset[$i];
            $supersetValue = $superset[$j];

            if ($subsetValue === $supersetValue) {
                ++$i;
                ++$j;
                continue;
            }

            if ($subsetValue > $supersetValue) {
                ++$j;
                continue;
            }

            return false;
        }

        return $i === $subsetCount;
    }
}
