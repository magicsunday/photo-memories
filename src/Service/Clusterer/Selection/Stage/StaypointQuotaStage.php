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

/**
 * Limits the number of members picked from identical staypoints.
 */
final class StaypointQuotaStage implements SelectionStageInterface
{
    public function getName(): string
    {
        return SelectionTelemetry::REASON_STAYPOINT;
    }

    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
    {
        $maxPerStaypoint = $policy->getMaxPerStaypoint();
        if ($maxPerStaypoint === null || $maxPerStaypoint <= 0) {
            return $candidates;
        }

        $selected      = [];
        $countByStay   = [];

        foreach ($candidates as $candidate) {
            $staypoint = $candidate['staypoint'];
            if ($staypoint === null) {
                $selected[] = $candidate;

                continue;
            }

            if (($countByStay[$staypoint] ?? 0) >= $maxPerStaypoint) {
                $telemetry->increment(SelectionTelemetry::REASON_STAYPOINT);

                continue;
            }

            $selected[] = $candidate;
            $countByStay[$staypoint] = ($countByStay[$staypoint] ?? 0) + 1;
        }

        return $selected;
    }
}
