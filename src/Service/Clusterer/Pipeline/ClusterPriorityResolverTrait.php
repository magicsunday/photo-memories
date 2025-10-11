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

use function count;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * Provides shared priority comparison helpers for consolidation stages.
 */
trait ClusterPriorityResolverTrait
{
    /**
     * @param array<string, int> $priorityMap
     * @param list<int>          $leftMembers
     * @param list<int>          $rightMembers
     */
    private function preferLeft(
        ClusterDraft $left,
        array $leftMembers,
        ClusterDraft $right,
        array $rightMembers,
        array $priorityMap,
    ): bool {
        $priorityLeft  = (int) ($priorityMap[$left->getAlgorithm()] ?? 0);
        $priorityRight = (int) ($priorityMap[$right->getAlgorithm()] ?? 0);
        if ($priorityLeft !== $priorityRight) {
            return $priorityLeft > $priorityRight;
        }

        $classificationLeft  = $this->resolveClassificationRank($left);
        $classificationRight = $this->resolveClassificationRank($right);
        if ($classificationLeft !== $classificationRight) {
            return $classificationLeft > $classificationRight;
        }

        $countLeft  = count($leftMembers);
        $countRight = count($rightMembers);
        if ($countLeft !== $countRight) {
            return $countLeft > $countRight;
        }

        $scoreLeft  = $this->computeScore($left, $leftMembers);
        $scoreRight = $this->computeScore($right, $rightMembers);
        if ($scoreLeft !== $scoreRight) {
            return $scoreLeft > $scoreRight;
        }

        $durationLeft  = $this->resolveDurationSeconds($left);
        $durationRight = $this->resolveDurationSeconds($right);
        if ($durationLeft !== $durationRight) {
            return $durationLeft > $durationRight;
        }

        return true;
    }

    private function resolveClassificationRank(ClusterDraft $draft): int
    {
        if ($draft->getAlgorithm() !== 'vacation') {
            return 0;
        }

        $classification = $draft->getParams()['classification'] ?? null;
        if (!is_string($classification) || $classification === '') {
            return 0;
        }

        return match ($classification) {
            'vacation' => 30,
            'short_trip' => 20,
            'day_trip' => 10,
            default => 0,
        };
    }

    private function resolveDurationSeconds(ClusterDraft $draft): int
    {
        $range = $draft->getParams()['time_range'] ?? null;
        if (!is_array($range) || !isset($range['from'], $range['to'])) {
            return 0;
        }

        $from = $range['from'];
        $to   = $range['to'];
        if (!is_numeric($from) || !is_numeric($to)) {
            return 0;
        }

        $fromTs = (int) $from;
        $toTs   = (int) $to;
        if ($toTs <= $fromTs) {
            return 0;
        }

        return $toTs - $fromTs;
    }
}
