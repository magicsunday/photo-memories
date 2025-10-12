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

use function array_key_exists;
use function array_sum;
use function ceil;
use function count;
use function is_string;

/**
 * Promotes scene diversity by limiting repeated bucket assignments.
 */
final class SceneDiversityStage implements SelectionStageInterface
{
    private const DEFAULT_WEIGHTS = [
        'person_group' => 0.18,
        'landmark'     => 0.16,
        'food'         => 0.14,
        'indoor'       => 0.16,
        'outdoor'      => 0.20,
        'night'        => 0.10,
        'panorama'     => 0.06,
    ];

    public function getName(): string
    {
        return SelectionTelemetry::REASON_SCENE;
    }

    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
    {
        if ($candidates === []) {
            return [];
        }

        $weights = $this->normaliseWeights($policy->getSceneBucketWeights());
        $ratios  = $this->computeRatios($weights);
        $fallbackRatio = count($ratios) > 0 ? 1.0 / count($ratios) : 1.0;

        $selected      = [];
        $countByBucket = [];

        foreach ($candidates as $candidate) {
            $bucket = $candidate['bucket'] ?? null;
            if (!is_string($bucket) || $bucket === '') {
                $selected[] = $candidate;

                continue;
            }

            $ratio = $ratios[$bucket] ?? $fallbackRatio;
            $limit = $this->shareLimit($ratio, count($selected) + 1);

            if (($countByBucket[$bucket] ?? 0) >= $limit) {
                $telemetry->increment(SelectionTelemetry::REASON_SCENE);

                continue;
            }

            $selected[] = $candidate;
            $countByBucket[$bucket] = ($countByBucket[$bucket] ?? 0) + 1;
        }

        return $selected;
    }

    /**
     * @param array<string, float> $weights
     *
     * @return array<string, float>
     */
    private function normaliseWeights(array $weights): array
    {
        $normalised = [];

        foreach (self::DEFAULT_WEIGHTS as $bucket => $defaultWeight) {
            $weight = $weights[$bucket] ?? $defaultWeight;
            if ($weight <= 0.0) {
                $weight = $defaultWeight;
            }

            $normalised[$bucket] = $weight;
        }

        foreach ($weights as $bucket => $weight) {
            if ($weight <= 0.0) {
                continue;
            }

            if (array_key_exists($bucket, $normalised)) {
                continue;
            }

            $normalised[$bucket] = $weight;
        }

        return $normalised;
    }

    /**
     * @param array<string, float> $weights
     *
     * @return array<string, float>
     */
    private function computeRatios(array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $total = array_sum($weights);
        if ($total <= 0.0) {
            $count = count($weights);
            if ($count === 0) {
                return [];
            }

            $ratio   = 1.0 / $count;
            $ratios = [];
            foreach ($weights as $bucket => $_weight) {
                $ratios[$bucket] = $ratio;
            }

            return $ratios;
        }

        $ratios = [];
        foreach ($weights as $bucket => $weight) {
            $ratios[$bucket] = $weight / $total;
        }

        return $ratios;
    }

    private function shareLimit(float $ratio, int $nextTotal): int
    {
        $limit = (int) ceil($nextTotal * $ratio);

        return $limit >= 1 ? $limit : 1;
    }
}
