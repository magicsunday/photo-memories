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
use function is_int;

/**
 * Ensures temporal spacing constraints are honoured across the full selection.
 */
final class TimeGapStage implements SelectionStageInterface
{
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
        $lastTimestampGlobal = null;
        $minSpacing          = max(0, $policy->getMinSpacingSeconds());

        foreach ($candidates as $candidate) {
            $timestamp = $candidate['timestamp'] ?? null;
            if (!is_int($timestamp)) {
                $selected[] = $candidate;

                continue;
            }

            if ($lastTimestampGlobal !== null && abs($timestamp - $lastTimestampGlobal) < $minSpacing) {
                $telemetry->increment(SelectionTelemetry::REASON_TIME_GAP);

                continue;
            }

            $selected[]          = $candidate;
            $lastTimestampGlobal = $timestamp;
        }

        return $selected;
    }
}
