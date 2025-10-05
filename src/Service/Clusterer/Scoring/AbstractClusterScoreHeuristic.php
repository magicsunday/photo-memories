<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;

use function abs;
use function is_numeric;
use function is_string;
use function log;

/**
 * Class AbstractClusterScoreHeuristic
 */
abstract class AbstractClusterScoreHeuristic implements ClusterScoreHeuristicInterface
{
    public function prepare(array $clusters, array $mediaMap): void
    {
    }

    /**
     * @param array<int, Media> $mediaMap
     *
     * @return list<Media>
     */
    protected function collectMediaItems(ClusterDraft $cluster, array $mediaMap): array
    {
        $items = [];
        foreach ($cluster->getMembers() as $id) {
            $media = $mediaMap[$id] ?? null;
            if ($media instanceof Media) {
                $items[] = $media;
            }
        }

        return $items;
    }

    protected function clamp01(?float $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    protected function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<array{0: float|null, 1: float}> $components
     */
    protected function combineScores(array $components, ?float $default = 0.0): float
    {
        $sum       = 0.0;
        $weightSum = 0.0;

        foreach ($components as [$value, $weight]) {
            if ($value === null) {
                continue;
            }

            $sum += $this->clamp01($value) * $weight;
            $weightSum += $weight;
        }

        if ($weightSum <= 0.0) {
            return $default ?? 0.0;
        }

        return $sum / $weightSum;
    }

    protected function balancedScore(float $value, float $target, float $tolerance): float
    {
        $delta = abs($value - $target);
        if ($delta >= $tolerance) {
            return 0.0;
        }

        return $this->clamp01(1.0 - ($delta / $tolerance));
    }

    protected function normalizeIso(int $iso): float
    {
        $min   = 50.0;
        $max   = 6400.0;
        $iso   = (float) max($min, min($max, $iso));
        $ratio = log($iso / $min) / log($max / $min);

        return $this->clamp01(1.0 - $ratio);
    }

    protected function spanScore(float $durationSeconds): float
    {
        $hours = $durationSeconds / 3600.0;

        if ($hours <= 0.5) {
            return 1.0;
        }

        if ($hours >= 240.0) {
            return 0.0;
        }

        if ($hours <= 48.0) {
            return $this->clamp01(1.0 - (($hours - 0.5) / 47.5) * 0.4);
        }

        return $this->clamp01(0.6 - (($hours - 48.0) / 192.0) * 0.6);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
