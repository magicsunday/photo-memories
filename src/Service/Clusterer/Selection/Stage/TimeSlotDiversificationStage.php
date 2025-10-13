<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection\Stage;

use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicy;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;

use function abs;
use function ceil;
use function count;
use function floor;
use function is_int;
use function is_string;
use function max;
use function min;

/**
 * Balances intraday picks across temporal slots and adapts spacing as selection progresses.
 */
final class TimeSlotDiversificationStage implements SelectionStageInterface
{
    private const SLOT_CAP = 2;

    public function getName(): string
    {
        return SelectionTelemetry::REASON_TIME_SLOT;
    }

    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
    {
        if ($candidates === []) {
            return [];
        }

        $selected       = [];
        $countBySlot    = [];
        $lastTimestamp  = [];
        $targetTotal    = max(1, $policy->getTargetTotal());
        $baseSpacing    = max(0, $policy->getMinSpacingSeconds());
        $progressFactor = min(1.0, max(0.0, $policy->getSpacingProgressFactor()));
        $dayContext     = $policy->getDayContext();
        $dayQuotas      = $policy->getDayQuotas();
        $maxPerDay      = $policy->getMaxPerDay();

        $uniqueDays = [];
        foreach ($candidates as $candidate) {
            $day = $candidate['day'] ?? null;
            if (is_string($day) && $day !== '') {
                $uniqueDays[$day] = true;
            }
        }

        if ($uniqueDays === [] && $dayQuotas !== []) {
            foreach ($dayQuotas as $day => $quota) {
                if (is_string($day) && $day !== '') {
                    $uniqueDays[$day] = true;
                }
            }
        }

        if ($uniqueDays === [] && $dayContext !== []) {
            foreach ($dayContext as $day => $_) {
                if (is_string($day) && $day !== '') {
                    $uniqueDays[$day] = true;
                }
            }
        }

        $runDays = count($uniqueDays);
        if ($runDays <= 0) {
            $runDays = 1;
        }

        $defaultPerDayCap = null;
        if (is_int($maxPerDay) && $maxPerDay > 0) {
            $defaultPerDayCap = $maxPerDay;
        } else {
            $defaultPerDayCap = (int) ceil($targetTotal / $runDays);
            if ($defaultPerDayCap <= 0) {
                $defaultPerDayCap = 1;
            }
        }

        foreach ($candidates as $candidate) {
            $day  = $candidate['day'] ?? null;
            $slot = $candidate['slot'] ?? null;
            if (!is_string($day) || $day === '') {
                $selected[] = $candidate;

                continue;
            }

            if ($slot !== null) {
                $slotKey = $day . '#' . $slot;
                if (($countBySlot[$slotKey] ?? 0) >= self::SLOT_CAP) {
                    $telemetry->increment(SelectionTelemetry::REASON_TIME_SLOT);

                    continue;
                }
            }

            $timestamp = $candidate['timestamp'] ?? null;
            if (!is_int($timestamp)) {
                $selected[] = $candidate;

                continue;
            }

            $currentProgress = count($selected) / $targetTotal;
            $requiredSpacing = $baseSpacing;

            $perDayCap = $dayQuotas[$day] ?? null;
            if (!is_int($perDayCap) || $perDayCap < 0) {
                $perDayCap = $defaultPerDayCap;
            }

            $dayDuration = $candidate['day_duration'] ?? ($dayContext[$day]['duration'] ?? null);
            if (is_int($dayDuration) && $dayDuration > 0) {
                $quotaSpacing = (int) ceil($dayDuration / max(3, $perDayCap + 1));
                $requiredSpacing = max($requiredSpacing, $quotaSpacing);
            }

            if ($baseSpacing > 0 && $progressFactor > 0.0) {
                $remainingProgress = max(0.0, 1.0 - min(1.0, $currentProgress));
                $progressiveBonus  = (int) floor($baseSpacing * $progressFactor * $remainingProgress);
                $requiredSpacing   = max($requiredSpacing, $baseSpacing + $progressiveBonus);
            }

            if ($requiredSpacing > 0 && isset($lastTimestamp[$day]) && abs($timestamp - $lastTimestamp[$day]) < $requiredSpacing) {
                $telemetry->increment(SelectionTelemetry::REASON_TIME_SLOT);

                continue;
            }

            $selected[]        = $candidate;
            $lastTimestamp[$day] = $timestamp;

            if ($slot !== null) {
                $slotKey = $day . '#' . $slot;
                $countBySlot[$slotKey] = ($countBySlot[$slotKey] ?? 0) + 1;
            }
        }

        return $selected;
    }
}
