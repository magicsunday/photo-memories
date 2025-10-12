<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Service;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\VacationRunDetectorInterface;
use MagicSunday\Memories\Clusterer\Support\HomeBoundaryHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_keys;
use function count;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function pi;
use function strtolower;

/**
 * Detects vacation runs based on daily summaries.
 */
final class RunDetector implements VacationRunDetectorInterface
{
    private const TRANSIT_RATIO_THRESHOLD = 0.6;
    private const TRANSIT_SPEED_THRESHOLD = 90.0;

    /**
     * @param float                                     $minAwayDistanceKm       minimum distance from home to count as away day
     * @param int                                       $minItemsPerDay          minimum number of items required to bridge runs
     * @param list<array{
     *     distance_km:float,
     *     min_center_count?:int,
     *     min_total_member_count?:int,
     *     max_primary_radius_km?:float,
     *     min_primary_density?:float,
     *     countries?:list<string>
     * }> $minAwayDistanceProfiles
     */
    public function __construct(
        private TransportDayExtender $transportDayExtender,
        private float $minAwayDistanceKm = 120.0,
        private int $minItemsPerDay = 3,
        private array $minAwayDistanceProfiles = [],
    ) {
        if ($this->minAwayDistanceKm <= 0.0) {
            throw new InvalidArgumentException('minAwayDistanceKm must be > 0.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        $this->minAwayDistanceProfiles = $this->sanitizeDistanceProfiles($this->minAwayDistanceProfiles);
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

        $effectiveMinAwayDistanceKm = $this->determineEffectiveMinAwayDistance($home);

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
                'timestamp'            => $this->summaryTimestamp($summary),
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
                    $centroid   = MediaMath::centroid($features['gpsMembers']);
                    $nearest    = HomeBoundaryHelper::nearestCenter(
                        $home,
                        $centroid['lat'],
                        $centroid['lon'],
                        $features['timestamp'],
                    );

                    if ($nearest['distance_km'] > $nearest['radius_km']) {
                        $candidate = true;
                    }
                }

                if ($candidate === false && $hasUsefulSamples && $features['maxDistanceKm'] > $effectiveMinAwayDistanceKm) {
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
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int,valid_from?:int|null,valid_until?:int|null}>} $home
     */
    private function determineEffectiveMinAwayDistance(array $home): float
    {
        if ($this->minAwayDistanceProfiles === []) {
            return $this->minAwayDistanceKm;
        }

        $centers       = HomeBoundaryHelper::centers($home);
        $centerCount   = count($centers);
        $totalMembers  = 0;
        $primary       = $centers[0];
        $primaryRadius = (float) ($primary['radius_km'] ?? 0.0);
        $primaryMembers = (int) ($primary['member_count'] ?? 0);
        $primaryCountry = null;

        if (isset($primary['country']) && is_string($primary['country']) && $primary['country'] !== '') {
            $primaryCountry = strtolower($primary['country']);
        }

        foreach ($centers as $center) {
            $totalMembers += (int) ($center['member_count'] ?? 0);
        }

        $primaryDensity = 0.0;
        if ($primaryRadius > 0.0 && $primaryMembers > 0) {
            $areaKm2       = pi() * $primaryRadius * $primaryRadius;
            $primaryDensity = $areaKm2 > 0.0 ? $primaryMembers / $areaKm2 : 0.0;
        }

        $effective = $this->minAwayDistanceKm;

        foreach ($this->minAwayDistanceProfiles as $profile) {
            if ($this->matchesDistanceProfile($profile, $centerCount, $totalMembers, $primaryRadius, $primaryDensity, $primaryCountry)) {
                $effective = min($effective, $profile['distance_km']);
            }
        }

        return $effective;
    }

    /**
     * @param array{distance_km:float,min_center_count?:int,min_total_member_count?:int,max_primary_radius_km?:float,min_primary_density?:float,countries?:list<string>} $profile
     */
    private function matchesDistanceProfile(
        array $profile,
        int $centerCount,
        int $totalMembers,
        float $primaryRadius,
        float $primaryDensity,
        ?string $primaryCountry,
    ): bool {
        $minCenterCount = $profile['min_center_count'] ?? null;
        if (is_int($minCenterCount) && $minCenterCount > 0 && $centerCount < $minCenterCount) {
            return false;
        }

        $minTotalMembers = $profile['min_total_member_count'] ?? null;
        if (is_int($minTotalMembers) && $minTotalMembers > 0 && $totalMembers < $minTotalMembers) {
            return false;
        }

        $maxPrimaryRadius = $profile['max_primary_radius_km'] ?? null;
        if ((is_float($maxPrimaryRadius) || is_int($maxPrimaryRadius)) && (float) $maxPrimaryRadius > 0.0 && $primaryRadius > (float) $maxPrimaryRadius) {
            return false;
        }

        $minPrimaryDensity = $profile['min_primary_density'] ?? null;
        if ((is_float($minPrimaryDensity) || is_int($minPrimaryDensity)) && (float) $minPrimaryDensity > 0.0 && $primaryDensity < (float) $minPrimaryDensity) {
            return false;
        }

        if (isset($profile['countries']) && is_array($profile['countries']) && $profile['countries'] !== []) {
            if ($primaryCountry === null || !in_array($primaryCountry, $profile['countries'], true)) {
                return false;
            }
        }

        return true;
    }

    private function summaryTimestamp(array $summary): ?int
    {
        $members = $summary['gpsMembers'] ?? null;
        if (is_array($members)) {
            foreach ($members as $member) {
                if (!$member instanceof Media) {
                    continue;
                }

                $takenAt = $member->getTakenAt();
                if ($takenAt instanceof DateTimeImmutable) {
                    return $takenAt->getTimestamp();
                }
            }
        }

        $date = $summary['date'] ?? null;
        if (!is_string($date) || $date === '') {
            return null;
        }

        $tzId = $summary['localTimezoneIdentifier'] ?? null;
        $tz   = null;

        if (is_string($tzId) && $tzId !== '') {
            try {
                $tz = new DateTimeZone($tzId);
            } catch (Exception) {
                $tz = null;
            }
        }

        try {
            $reference = $tz instanceof DateTimeZone
                ? new DateTimeImmutable($date . ' 12:00:00', $tz)
                : new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        return $reference->getTimestamp();
    }

    /**
     * @param list<array{distance_km:float,min_center_count?:int,min_total_member_count?:int,max_primary_radius_km?:float,min_primary_density?:float,countries?:list<string>}> $profiles
     *
     * @return list<array{distance_km:float,min_center_count?:int,min_total_member_count?:int,max_primary_radius_km?:float,min_primary_density?:float,countries?:list<string>}>
     */
    private function sanitizeDistanceProfiles(array $profiles): array
    {
        $result = [];

        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $distance = $profile['distance_km'] ?? null;
            if (!is_float($distance) && !is_int($distance)) {
                continue;
            }

            $distanceValue = (float) $distance;
            if ($distanceValue <= 0.0) {
                continue;
            }

            $entry = ['distance_km' => $distanceValue];

            if (isset($profile['min_center_count']) && is_int($profile['min_center_count']) && $profile['min_center_count'] > 0) {
                $entry['min_center_count'] = $profile['min_center_count'];
            }

            if (isset($profile['min_total_member_count']) && is_int($profile['min_total_member_count']) && $profile['min_total_member_count'] > 0) {
                $entry['min_total_member_count'] = $profile['min_total_member_count'];
            }

            if (isset($profile['max_primary_radius_km']) && (is_float($profile['max_primary_radius_km']) || is_int($profile['max_primary_radius_km']))) {
                $radius = (float) $profile['max_primary_radius_km'];
                if ($radius > 0.0) {
                    $entry['max_primary_radius_km'] = $radius;
                }
            }

            if (isset($profile['min_primary_density']) && (is_float($profile['min_primary_density']) || is_int($profile['min_primary_density']))) {
                $density = (float) $profile['min_primary_density'];
                if ($density > 0.0) {
                    $entry['min_primary_density'] = $density;
                }
            }

            if (isset($profile['countries']) && is_array($profile['countries'])) {
                $countries = [];
                foreach ($profile['countries'] as $country) {
                    if (!is_string($country) || $country === '') {
                        continue;
                    }

                    $countries[] = strtolower($country);
                }

                if ($countries !== []) {
                    $entry['countries'] = $countries;
                }
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param array<string, mixed>                                         $summary
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int,valid_from?:int|null,valid_until?:int|null}>} $home
     */
    private function isDominantStaypointOutsideHome(array $summary, array $home): bool
    {
        $dominant = $summary['dominantStaypoints'] ?? [];
        if ($dominant === []) {
            return false;
        }

        $primary = $dominant[0];

        $timestamp = $this->summaryTimestamp($summary);

        return HomeBoundaryHelper::isBeyondHome(
            $home,
            (float) $primary['lat'],
            (float) $primary['lon'],
            true,
            $timestamp,
        );
    }

    /**
     * @param array<string, mixed>                                         $summary
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int,valid_from?:int|null,valid_until?:int|null}>} $home
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
