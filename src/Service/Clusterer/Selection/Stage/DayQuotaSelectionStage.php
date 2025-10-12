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

use function is_int;
use function is_string;

/**
 * Enforces per-day quotas derived from the policy runtime context.
 */
final class DayQuotaSelectionStage implements SelectionStageInterface
{
    public function getName(): string
    {
        return SelectionTelemetry::REASON_DAY_QUOTA;
    }

    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
    {
        if ($candidates === []) {
            return [];
        }

        $quotas = $policy->getDayQuotas();
        if ($quotas === []) {
            return $candidates;
        }

        $selected = [];
        $counts   = [];

        foreach ($candidates as $candidate) {
            $day = $candidate['day'] ?? null;
            if (!is_string($day) || $day === '') {
                $selected[] = $candidate;

                continue;
            }

            $limit = $quotas[$day] ?? null;
            if (is_int($limit) && $limit >= 0 && ($counts[$day] ?? 0) >= $limit) {
                $telemetry->increment(SelectionTelemetry::REASON_DAY_QUOTA);

                continue;
            }

            $selected[]       = $candidate;
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        return $selected;
    }
}
