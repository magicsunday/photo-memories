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

use function ceil;
use function max;

/**
 * Avoids over-representing a single orientation (portrait/landscape).
 */
final class OrientationBalanceStage implements SelectionStageInterface
{
    private const MAX_SHARE = 0.6;

    public function getName(): string
    {
        return SelectionTelemetry::REASON_ORIENTATION;
    }

    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
    {
        if ($candidates === []) {
            return [];
        }

        $selected        = [];
        $countByType     = [];

        foreach ($candidates as $candidate) {
            $type = $candidate['orientation'];
            if ($type === null) {
                $selected[] = $candidate;

                continue;
            }

            $nextTotal = count($selected) + 1;
            $limit     = max(1, (int) ceil($nextTotal * self::MAX_SHARE));

            if (($countByType[$type] ?? 0) + 1 > $limit) {
                $telemetry->increment(SelectionTelemetry::REASON_ORIENTATION);

                continue;
            }

            $selected[] = $candidate;
            $countByType[$type] = ($countByType[$type] ?? 0) + 1;
        }

        return $selected;
    }
}
