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

use function array_filter;
use function array_fill;
use function array_map;
use function array_values;
use function count;

use const ARRAY_FILTER_USE_BOTH;

/**
 * Resolves strongly overlapping clusters by keeping the preferred candidate.
 */
final class OverlapResolverStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;
    use ClusterPriorityResolverTrait;

    /** @var array<string, int> */
    private array $priorityMap = [];

    /**
     * @param list<string> $keepOrder
     */
    public function __construct(
        private readonly float $mergeThreshold,
        private readonly float $dropThreshold,
        array $keepOrder,
    ) {
        if ($this->mergeThreshold <= 0.0 || $this->mergeThreshold > 1.0) {
            throw new InvalidArgumentException('mergeThreshold must be between 0 and 1.');
        }

        if ($this->dropThreshold < $this->mergeThreshold || $this->dropThreshold > 1.0) {
            throw new InvalidArgumentException('dropThreshold must be >= mergeThreshold and <= 1.');
        }

        $base = count($keepOrder);
        foreach ($keepOrder as $index => $algorithm) {
            $this->priorityMap[$algorithm] = $base - $index;
        }
    }

    public function getLabel(): string
    {
        return 'Überlappungen';
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

                $overlap = $this->jaccard($normalized[$i], $normalized[$j]);
                if ($overlap < $this->mergeThreshold) {
                    continue;
                }

                $requiresResolution = $overlap >= $this->dropThreshold
                    || $drafts[$i]->getAlgorithm() === $drafts[$j]->getAlgorithm();

                if (!$requiresResolution) {
                    continue;
                }

                $preferLeft = $this->preferLeft(
                    $drafts[$i],
                    $normalized[$i],
                    $drafts[$j],
                    $normalized[$j],
                    $this->priorityMap,
                );

                if ($preferLeft) {
                    $keep[$j] = false;
                } else {
                    $keep[$i] = false;
                    break;
                }
            }

            if ($progress !== null && ($i % 200) === 0) {
                $progress($i, $total);
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
}
