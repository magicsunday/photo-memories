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

/**
 * Ensures temporal spacing constraints and per-day slot limits are honoured.
 */
final class TimeGapStage implements SelectionStageInterface
{
    private const SLOT_CAP = 2;

    public function getName(): string
    {
        return SelectionTelemetry::REASON_TIME_GAP;
    }

    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
    {
        if ($candidates === []) {
            return [];
        }

        $selected            = [];
        $countByDay          = [];
        $countBySlot         = [];
        $lastTimestampGlobal = null;
        $lastTimestampPerDay = [];
        $minSpacing          = $policy->getMinSpacingSeconds();
        $maxPerDay           = $policy->getMaxPerDay();

        foreach ($candidates as $candidate) {
            $day       = $candidate['day'];
            $timestamp = $candidate['timestamp'];
            $slot      = $candidate['slot'];

            if ($maxPerDay !== null && ($countByDay[$day] ?? 0) >= $maxPerDay) {
                $telemetry->increment(SelectionTelemetry::REASON_TIME_GAP);

                continue;
            }

            if ($slot !== null) {
                $slotKey = $day . '#' . $slot;
                if (($countBySlot[$slotKey] ?? 0) >= self::SLOT_CAP) {
                    $telemetry->increment(SelectionTelemetry::REASON_TIME_GAP);

                    continue;
                }
            }

            if ($lastTimestampGlobal !== null && abs($timestamp - $lastTimestampGlobal) < $minSpacing) {
                $telemetry->increment(SelectionTelemetry::REASON_TIME_GAP);

                continue;
            }

            if (isset($lastTimestampPerDay[$day]) && abs($timestamp - $lastTimestampPerDay[$day]) < $minSpacing) {
                $telemetry->increment(SelectionTelemetry::REASON_TIME_GAP);

                continue;
            }

            $selected[] = $candidate;
            $lastTimestampGlobal = $timestamp;
            $lastTimestampPerDay[$day] = $timestamp;
            $countByDay[$day] = ($countByDay[$day] ?? 0) + 1;

            if ($slot !== null) {
                $slotKey = $day . '#' . $slot;
                $countBySlot[$slotKey] = ($countBySlot[$slotKey] ?? 0) + 1;
            }
        }

        return $selected;
    }
}
