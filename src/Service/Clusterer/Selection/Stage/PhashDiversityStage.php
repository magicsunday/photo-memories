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
use function array_key_exists;
use function count;
use function floor;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function min;
use function sort;
use const SORT_NUMERIC;

/**
 * Removes members that fall below the perceptual hash distance threshold.
 */
final class PhashDiversityStage implements SelectionStageInterface
{
    /**
     * @var array<string, int>
     */
    private array $distanceCache = [];

    public function getName(): string
    {
        return SelectionTelemetry::REASON_PHASH;
    }

    public function apply(array $candidates, SelectionPolicy $policy, SelectionTelemetry $telemetry): array
    {
        $threshold = $policy->getPhashMinHamming();
        if ($threshold <= 0) {
            return $candidates;
        }

        $adaptiveThreshold = $this->determineAdaptiveThreshold($candidates, $policy);
        if ($adaptiveThreshold !== null) {
            $threshold = max(1, min($threshold, $adaptiveThreshold));
        }

        $this->distanceCache = [];
        $selected = [];

        foreach ($candidates as $candidate) {
            $isDuplicate = false;

            foreach ($selected as $existing) {
                $distance = $this->hammingDistance($candidate, $existing);
                if ($distance !== null && $distance < $threshold) {
                    $telemetry->increment(SelectionTelemetry::REASON_PHASH);
                    $isDuplicate = true;

                    break;
                }
            }

            if ($isDuplicate) {
                continue;
            }

            $selected[] = $candidate;
        }

        return $selected;
    }

    private function hammingDistance(array $a, array $b): ?int
    {
        $hashA = $a['hash_bits'];
        $hashB = $b['hash_bits'];
        if ($hashA === null || $hashB === null) {
            return null;
        }

        $cacheKey = $a['id'] < $b['id'] ? $a['id'] . ':' . $b['id'] : $b['id'] . ':' . $a['id'];
        if (array_key_exists($cacheKey, $this->distanceCache)) {
            return $this->distanceCache[$cacheKey];
        }

        $distance = $this->distanceFromBits($hashA, $hashB);

        $this->distanceCache[$cacheKey] = $distance;

        return $distance;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function determineAdaptiveThreshold(array $candidates, SelectionPolicy $policy): ?int
    {
        $percentile = $policy->getPhashPercentile();
        if ($percentile <= 0.0) {
            return null;
        }

        $distances = [];
        $total     = count($candidates);

        for ($i = 0; $i < $total; ++$i) {
            $hashA = $candidates[$i]['hash_bits'] ?? null;
            if (!is_array($hashA)) {
                continue;
            }

            for ($j = $i + 1; $j < $total; ++$j) {
                $hashB = $candidates[$j]['hash_bits'] ?? null;
                if (!is_array($hashB)) {
                    continue;
                }

                $distance = $this->distanceFromBits($hashA, $hashB);
                $distances[] = $distance;
            }
        }

        if ($distances === []) {
            return null;
        }

        sort($distances, SORT_NUMERIC);
        $index = (int) floor($percentile * (count($distances) - 1));
        if ($index < 0) {
            $index = 0;
        }

        if ($index >= count($distances)) {
            $index = count($distances) - 1;
        }

        return $distances[$index];
    }

    /**
     * @param list<int> $hashA
     * @param list<int> $hashB
     */
    private function distanceFromBits(array $hashA, array $hashB): int
    {
        $length   = min(count($hashA), count($hashB));
        $distance = abs(count($hashA) - count($hashB));
        for ($idx = 0; $idx < $length; ++$idx) {
            if ($hashA[$idx] !== $hashB[$idx]) {
                ++$distance;
            }
        }

        return $distance;
    }
}
