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
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;

use function count;
use function max;

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
        private readonly ?JobMonitoringEmitterInterface $monitoringEmitter = null,
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
        $total                = count($drafts);
        $droppedInvalidTime   = 0;
        $droppedTooSmall      = 0;
        $droppedBelowMinScore = 0;

        $this->emitMonitoring('selection_start', [
            'pre_count'          => $total,
            'min_score'          => $this->minScore,
            'min_size'           => $this->minSize,
            'require_valid_time' => $this->requireValidTime,
            'min_valid_year'     => $this->minValidYear,
        ]);

        if ($progress !== null) {
            $progress(0, $total);
        }

        if ($total === 0) {
            $this->emitMonitoring('selection_completed', [
                'pre_count'             => 0,
                'post_count'            => 0,
                'dropped_count'         => 0,
                'dropped_invalid_time'  => 0,
                'dropped_below_min_size'=> 0,
                'dropped_below_min_score' => 0,
            ]);

            return $drafts;
        }

        /** @var list<ClusterDraft> $kept */
        $kept  = [];
        $index = 0;
        foreach ($drafts as $draft) {
            ++$index;
            if ($progress !== null && ($index % 200) === 0) {
                $progress($index, $total);
            }

            if ($this->requireValidTime && !$this->hasValidTimeRange($draft, $this->minValidYear)) {
                ++$droppedInvalidTime;
                continue;
            }

            $normalized = $this->normalizeMembers($draft->getMembers());
            if (count($normalized) < $this->minSize) {
                ++$droppedTooSmall;
                continue;
            }

            if ($this->computeScore($draft, $normalized) < $this->minScore) {
                ++$droppedBelowMinScore;
                continue;
            }

            $kept[] = $draft;
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        $postCount = count($kept);

        $this->emitMonitoring('selection_completed', [
            'pre_count'               => $total,
            'post_count'              => $postCount,
            'dropped_count'           => max(0, $total - $postCount),
            'dropped_invalid_time'    => $droppedInvalidTime,
            'dropped_below_min_size'  => $droppedTooSmall,
            'dropped_below_min_score' => $droppedBelowMinScore,
        ]);

        return $kept;
    }

    /**
     * @param array<string, int|float|bool> $payload
     */
    private function emitMonitoring(string $event, array $payload): void
    {
        if ($this->monitoringEmitter === null) {
            return;
        }

        $this->monitoringEmitter->emit('filter_normalization', $event, $payload);
    }
}
