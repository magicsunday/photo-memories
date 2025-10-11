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
use MagicSunday\Memories\Clusterer\Support\HomeBoundaryHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function array_keys;
use function count;

/**
 * Detects vacation runs based on daily summaries.
 */
final class RunDetector implements VacationRunDetectorInterface
{
    private const TRANSIT_RATIO_THRESHOLD = 0.6;
    private const TRANSIT_SPEED_THRESHOLD = 90.0;

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

        $metadata = [];
        foreach ($keys as $key) {
            $summary = $days[$key];

            $metadata[$key] = [
                'hasGpsAnchors'        => $this->hasGpsAnchors($summary),
                'dominantOutsideHome'  => $this->isDominantStaypointOutsideHome($summary, $home),
                'dominantInsideHome'   => $this->isDominantStaypointInsideHome($summary, $home),
                'transitHeavy'         => $this->isTransitHeavyDay($summary),
                'sufficientSamples'    => (bool) ($summary['sufficientSamples'] ?? false),
                'photoCount'           => (int) ($summary['photoCount'] ?? 0),
                'maxDistanceKm'        => (float) ($summary['maxDistanceKm'] ?? 0.0),
                'baseAway'             => (bool) ($summary['baseAway'] ?? false),
                'gpsMembers'           => $summary['gpsMembers'] ?? [],
            ];
        }

        $isAwayCandidate = [];
        foreach ($keys as $key) {
            $summary  = $days[$key];
            $features = $metadata[$key];

            $candidate = $features['baseAway'];

            if ($candidate === false && $features['dominantOutsideHome']) {
                $candidate = true;
            }

            if ($candidate === false && $features['hasGpsAnchors']) {
                $hasUsefulSamples = $features['sufficientSamples'] || $features['photoCount'] >= 2;

                if ($hasUsefulSamples) {
                    $centroid = MediaMath::centroid($features['gpsMembers']);
                    $nearest  = HomeBoundaryHelper::nearestCenter($home, $centroid['lat'], $centroid['lon']);

                    if ($nearest['distance_km'] > $nearest['radius_km']) {
                        $candidate = true;
                    }
                }

                if ($candidate === false && $hasUsefulSamples && $features['maxDistanceKm'] > $this->minAwayDistanceKm) {
                    $candidate = true;
                }
            }

            $isAwayCandidate[$key] = $candidate;
        }

        $countKeys = count($keys);

        $transitStreak = [];
        for ($i = 0; $i < $countKeys; ++$i) {
            $key      = $keys[$i];
            $features = $metadata[$key];

            if ($features['transitHeavy']) {
                $transitStreak[] = $key;
                continue;
            }

            if ($transitStreak !== [] && count($transitStreak) >= 2) {
                foreach ($transitStreak as $transitKey) {
                    $isAwayCandidate[$transitKey] = true;
                }
            }

            $transitStreak = [];
        }

        if ($transitStreak !== [] && count($transitStreak) >= 2) {
            foreach ($transitStreak as $transitKey) {
                $isAwayCandidate[$transitKey] = true;
            }
        }

        for ($i = 0; $i < $countKeys; ++$i) {
            $key      = $keys[$i];
            $features = $metadata[$key];

            if ($isAwayCandidate[$key] ?? false) {
                continue;
            }

            $prevIsAway = $i > 0 && ($isAwayCandidate[$keys[$i - 1]] ?? false);
            $nextIsAway = $i + 1 < $countKeys && ($isAwayCandidate[$keys[$i + 1]] ?? false);

            if (
                $prevIsAway
                && $nextIsAway
                && $features['photoCount'] < $this->minItemsPerDay
                && ($features['hasGpsAnchors'] || $features['transitHeavy'])
            ) {
                $isAwayCandidate[$key] = true;
            }
        }

        for ($i = 0; $i < $countKeys; ++$i) {
            $key      = $keys[$i];
            $features = $metadata[$key];

            if (($isAwayCandidate[$key] ?? false) || $features['transitHeavy'] === false) {
                continue;
            }

            $prevIsAway = $i > 0 && ($isAwayCandidate[$keys[$i - 1]] ?? false);
            $nextIsAway = $i + 1 < $countKeys && ($isAwayCandidate[$keys[$i + 1]] ?? false);

            if ($prevIsAway || $nextIsAway) {
                $isAwayCandidate[$key] = true;
            }
        }

        foreach ($keys as $key) {
            if (($isAwayCandidate[$key] ?? false) === false) {
                continue;
            }

            $features = $metadata[$key];

            if ($features['dominantInsideHome']) {
                $isAwayCandidate[$key] = false;
                continue;
            }

            if ($features['hasGpsAnchors'] === false && $features['transitHeavy'] === false) {
                $isAwayCandidate[$key] = false;
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

    /**
     * @param array<string, mixed>                                         $summary
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int}>} $home
     */
    private function isDominantStaypointOutsideHome(array $summary, array $home): bool
    {
        $dominant = $summary['dominantStaypoints'] ?? [];
        if ($dominant === []) {
            return false;
        }

        $primary = $dominant[0];

        return HomeBoundaryHelper::isBeyondHome(
            $home,
            (float) $primary['lat'],
            (float) $primary['lon'],
            true,
        );
    }

    /**
     * @param array<string, mixed>                                         $summary
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int}>} $home
     */
    private function isDominantStaypointInsideHome(array $summary, array $home): bool
    {
        $dominant = $summary['dominantStaypoints'] ?? [];
        if ($dominant === []) {
            return false;
        }

        return !$this->isDominantStaypointOutsideHome($summary, $home);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function hasGpsAnchors(array $summary): bool
    {
        $members = $summary['gpsMembers'] ?? [];

        if ($members === []) {
            return false;
        }

        return HomeBoundaryHelper::hasCoordinateSamples($members);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function isTransitHeavyDay(array $summary): bool
    {
        if (($summary['hasHighSpeedTransit'] ?? false) === true) {
            return true;
        }

        $ratio = (float) ($summary['transitRatio'] ?? 0.0);
        if ($ratio >= self::TRANSIT_RATIO_THRESHOLD) {
            return true;
        }

        $avgSpeed = (float) ($summary['avgSpeedKmh'] ?? 0.0);
        if ($avgSpeed >= self::TRANSIT_SPEED_THRESHOLD) {
            return true;
        }

        $maxSpeed = (float) ($summary['maxSpeedKmh'] ?? 0.0);
        if ($maxSpeed >= self::TRANSIT_SPEED_THRESHOLD) {
            return true;
        }

        return false;
    }
}
