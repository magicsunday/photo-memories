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

use function count;
use function max;
use function ceil;

/**
 * Promotes scene diversity by limiting repeated bucket assignments.
 */
final class SceneDiversityStage implements SelectionStageInterface
{
    public function getName(): string
    {
        return SelectionTelemetry::REASON_SCENE;
    }

    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
    {
        if ($candidates === []) {
            return [];
        }

        $maxPerBucket = $policy->getMaxPerBucket();
        if ($maxPerBucket === null) {
            $maxPerBucket = max(1, (int) ceil(count($candidates) / 4));
        }

        $selected      = [];
        $countByBucket = [];

        foreach ($candidates as $candidate) {
            $bucket = $candidate['bucket'];
            if ($bucket === null || $bucket === '') {
                $selected[] = $candidate;

                continue;
            }

            if (($countByBucket[$bucket] ?? 0) >= $maxPerBucket) {
                $telemetry->increment(SelectionTelemetry::REASON_SCENE);

                continue;
            }

            $selected[] = $candidate;
            $countByBucket[$bucket] = ($countByBucket[$bucket] ?? 0) + 1;
        }

        return $selected;
    }
}
