<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Service;

use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\VacationRunDetectorInterface;

use function array_keys;
use function count;

/**
 * Detects vacation runs based on daily summaries.
 */
final class RunDetector implements VacationRunDetectorInterface
{
    /**
     * @param float $minAwayDistanceKm minimum distance from home to count as away day
     * @param int   $minItemsPerDay    minimum number of items required to bridge runs
     */
    public function __construct(
        private TransportDayExtender $transportDayExtender,
        private float $minAwayDistanceKm = 120.0,
        private int $minItemsPerDay = 3,
    ) {
        if ($this->minAwayDistanceKm <= 0.0) {
            throw new InvalidArgumentException('minAwayDistanceKm must be > 0.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function detectVacationRuns(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        $keys       = array_keys($days);
        $indexByKey = [];
        foreach ($keys as $index => $key) {
            $indexByKey[$key] = $index;
        }

        $isAwayCandidate = [];
        foreach ($keys as $key) {
            $summary   = $days[$key];
            $candidate = $summary['baseAway'];

            if ($candidate === false && $summary['gpsMembers'] !== []) {
                $hasUsefulSamples = $summary['sufficientSamples'] || $summary['photoCount'] >= 2;

                if ($summary['avgDistanceKm'] > $home['radius_km'] && $hasUsefulSamples) {
                    $candidate = true;
                } elseif ($summary['maxDistanceKm'] > $this->minAwayDistanceKm && $hasUsefulSamples) {
                    $candidate = true;
                }
            }

            $isAwayCandidate[$key] = $candidate;
        }

        $countKeys = count($keys);
        for ($i = 0; $i < $countKeys; ++$i) {
            $key = $keys[$i];
            if ($isAwayCandidate[$key] ?? false) {
                continue;
            }

            $summary    = $days[$key];
            $gpsMembers = $summary['gpsMembers'];
            if ($gpsMembers === [] || $summary['photoCount'] < $this->minItemsPerDay) {
                $prevIsAway = $i > 0 && ($isAwayCandidate[$keys[$i - 1]] ?? false);
                $nextIsAway = $i + 1 < $countKeys && ($isAwayCandidate[$keys[$i + 1]] ?? false);
                if ($prevIsAway && $nextIsAway) {
                    $isAwayCandidate[$key] = true;
                }
            }
        }

        $runs  = [];
        $run   = [];
        $flush = function () use (&$run, &$runs, $keys, $indexByKey, $days): void {
            if ($run === []) {
                return;
            }

            $extended = $this->transportDayExtender->extend($run, $keys, $indexByKey, $days);
            if ($extended !== []) {
                $runs[] = $extended;
            }

            $run = [];
        };

        foreach ($keys as $key) {
            if (($isAwayCandidate[$key] ?? false) === false) {
                $flush();
                continue;
            }

            if ($run !== []) {
                $last = $run[count($run) - 1];
                if ($this->transportDayExtender->areSequentialDays($last, $key, $days) === false) {
                    $flush();
                }
            }

            $run[] = $key;
        }

        $flush();

        return $runs;
    }
}
