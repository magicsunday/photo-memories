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

use function count;

/**
 * Filters drafts by score, size and optional time validity.
 */
final class FilterNormalizationStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;

    public function __construct(
        private readonly float $minScore,
        private readonly int $minSize,
        private readonly bool $requireValidTime,
        private readonly int $minValidYear,
    ) {
    }

    public function getLabel(): string
    {
        return 'Filtern';
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterDraft>
     */
    public function process(array $drafts, ?callable $progress = null): array
    {
        $total = count($drafts);
        if ($progress !== null) {
            $progress(0, $total);
        }

        /** @var list<ClusterDraft> $kept */
        $kept = [];
        $index = 0;
        foreach ($drafts as $draft) {
            ++$index;
            if ($progress !== null && ($index % 200) === 0) {
                $progress($index, $total);
            }

            if ($this->requireValidTime && !$this->hasValidTimeRange($draft, $this->minValidYear)) {
                continue;
            }

            $normalized = $this->normalizeMembers($draft->getMembers());
            if (count($normalized) < $this->minSize) {
                continue;
            }

            if ($this->computeScore($draft, $normalized) < $this->minScore) {
                continue;
            }

            $kept[] = $draft;
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        return $kept;
    }
}
