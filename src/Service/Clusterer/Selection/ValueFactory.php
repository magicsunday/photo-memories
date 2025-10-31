<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection;

use MagicSunday\Memories\Service\Clusterer\Selection\Value\Derived;

use function array_fill_keys;
use function array_keys;
use function array_merge;
use function ceil;
use function count;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function sort;

/**
 * Computes derived runtime values for the selection pipeline.
 */
final class ValueFactory
{
    /**
     * @param list<array<string, mixed>> $candidates
     * @param array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}> $daySegments
     */
    public function create(SelectionPolicy $policy, array $candidates, array $daySegments): Derived
    {
        $dayQuotas    = $policy->getDayQuotas();
        $uniqueDays   = [];
        $dayDurations = [];

        foreach ($candidates as $candidate) {
            $day = $candidate['day'] ?? null;
            if (!is_string($day) || $day === '') {
                continue;
            }

            $uniqueDays[$day] = true;

            $duration = $candidate['day_duration'] ?? null;
            if ($duration === null) {
                continue;
            }

            if (is_int($duration) && $duration > 0) {
                $dayDurations[$day] = max($dayDurations[$day] ?? 0, $duration);

                continue;
            }

            if (is_numeric($duration)) {
                $duration = (int) $duration;
                if ($duration > 0) {
                    $dayDurations[$day] = max($dayDurations[$day] ?? 0, $duration);
                }
            }
        }

        $uniqueDays = array_merge(
            $uniqueDays,
            array_fill_keys(array_keys($dayQuotas), true),
            array_fill_keys(array_keys($daySegments), true),
        );

        $uniqueDayList = array_keys($uniqueDays);
        sort($uniqueDayList);

        $runDays = count($uniqueDayList);
        if ($runDays <= 0) {
            $runDays = 1;
        }

        $defaultPerDayCap = max(1, (int) ceil($policy->getTargetTotal() / $runDays));

        $quotaSpacingSeconds = [];
        $maxPerDay           = $policy->getMaxPerDay();
        foreach ($uniqueDayList as $day) {
            $duration = $dayDurations[$day] ?? null;
            if ($duration === null) {
                $duration = $this->extractDurationFromContext($daySegments, $day);
            }

            if ($duration === null || $duration <= 0) {
                continue;
            }

            $perDayCap = $dayQuotas[$day] ?? null;
            if (!is_int($perDayCap) || $perDayCap < 0) {
                if (is_int($maxPerDay) && $maxPerDay > 0) {
                    $perDayCap = $maxPerDay;
                } else {
                    $perDayCap = $defaultPerDayCap;
                }
            }

            $spacing = (int) ceil($duration / max(3, $perDayCap + 1));
            if ($spacing > 0) {
                $quotaSpacingSeconds[$day] = $spacing;
            }
        }

        return new Derived(
            runDays: $runDays,
            defaultPerDayCap: $defaultPerDayCap,
            uniqueDays: $uniqueDayList,
            quotaSpacingSeconds: $quotaSpacingSeconds,
        );
    }

    /**
     * @param array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}> $daySegments
     */
    private function extractDurationFromContext(array $daySegments, string $day): ?int
    {
        $info = $daySegments[$day] ?? null;
        if (!is_array($info)) {
            return null;
        }

        $duration = $info['duration'] ?? null;
        if ($duration === null) {
            return null;
        }

        if (is_int($duration) && $duration > 0) {
            return $duration;
        }

        if (is_numeric($duration)) {
            $duration = (int) $duration;
            if ($duration > 0) {
                return $duration;
            }
        }

        return null;
    }
}
