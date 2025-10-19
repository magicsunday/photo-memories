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
use function max;
use function pi;
use function strtolower;

/**
 * Detects vacation runs based on daily summaries.
 */
final class RunDetector implements VacationRunDetectorInterface
{
    private const TRANSIT_RATIO_THRESHOLD = 0.6;
    private const TRANSIT_SPEED_THRESHOLD = 90.0;
    private const MIN_STAYPOINT_BRIDGE_DWELL_SECONDS = 7200;

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
        private float $minAwayDistanceKm = 140.0,
        private int $minItemsPerDay = 4,
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

        [$strictMinAwayDistanceKm, $softMinAwayDistanceKm] = $this->determineEffectiveMinAwayDistanceBounds($home);

        $metadata = [];
        foreach ($keys as $key) {
            $summary       = $days[$key];
            $timestamp     = $this->summaryTimestamp($summary);
            $hasStaypoint  = $this->hasStaypointDwell($summary);
            $transitHeavy  = $this->isTransitHeavyDay($summary);
            $hasAirportPoi = (bool) ($summary['hasAirportPoi'] ?? false);
            $hasHighSpeed  = (bool) ($summary['hasHighSpeedTransit'] ?? false);
            $centroid      = $this->resolveDayCentroid($summary);

            $centroidDistance = null;
            $centroidRadius   = null;
            $centroidBeyond   = false;
            $distanceMetric   = (float) ($summary['maxDistanceKm'] ?? 0.0);

            if ($centroid !== null) {
                $nearest          = HomeBoundaryHelper::nearestCenter($home, $centroid['lat'], $centroid['lon'], $timestamp);
                $centroidDistance = $nearest['distance_km'];
                $centroidRadius   = $nearest['radius_km'];
                $centroidBeyond   = $centroidDistance > $centroidRadius;
                $distanceMetric   = $centroidDistance;
            }

            $metadata[$key] = [
                'hasGpsAnchors'        => $this->hasGpsAnchors($summary),
                'hasStaypointDwell'    => $hasStaypoint,
                'dominantOutsideHome'  => $this->isDominantStaypointOutsideHome($summary, $home),
                'dominantInsideHome'   => $this->isDominantStaypointInsideHome($summary, $home),
                'transitHeavy'         => $transitHeavy,
                'transportSignal'      => $transitHeavy || $hasAirportPoi || $hasHighSpeed,
                'hotelPoiSignal'       => $this->hasHotelPoiSignal($summary),
                'hasAirportPoi'        => $hasAirportPoi,
                'hasHighSpeedTransit'  => $hasHighSpeed,
                'sufficientSamples'    => (bool) ($summary['sufficientSamples'] ?? false),
                'photoCount'           => (int) ($summary['photoCount'] ?? 0),
                'maxDistanceKm'        => (float) ($summary['maxDistanceKm'] ?? 0.0),
                'distanceMetricKm'     => $distanceMetric,
                'centroidDistanceKm'   => $centroidDistance,
                'centroidRadiusKm'     => $centroidRadius,
                'centroidBeyondHome'   => $centroidBeyond,
                'baseAway'             => (bool) ($summary['baseAway'] ?? false),
                'gpsMembers'           => $summary['gpsMembers'] ?? [],
                'timestamp'            => $timestamp,
                'softDistanceEligible' => false,
            ];
        }

        $isAwayCandidate = [];
        $softDistanceEligible = [];
        foreach ($keys as $key) {
            $features = $metadata[$key];

            $distanceMetric       = $features['distanceMetricKm'];
            $strictDistanceMatch  = $distanceMetric > $strictMinAwayDistanceKm;
            $softDistanceMatch    = $distanceMetric > $softMinAwayDistanceKm;

            if ($strictDistanceMatch === false && $softDistanceMatch === true) {
                $metadata[$key]['softDistanceEligible'] = true;
                $softDistanceEligible[$key]             = true;
            }

            $candidate = $this->decideInitialAwayCandidate(
                $features,
                $strictDistanceMatch,
                $softDistanceMatch,
                $strictMinAwayDistanceKm,
            );

            if ($candidate === false && $features['hasGpsAnchors']) {
                $hasUsefulSamples = $features['sufficientSamples'] || $features['photoCount'] >= 2;

                if ($hasUsefulSamples && $features['centroidDistanceKm'] !== null && $features['centroidRadiusKm'] !== null) {
                    if ($features['centroidDistanceKm'] > $features['centroidRadiusKm']) {
                        $candidate = true;
                    }
                }

                if ($candidate === false && $hasUsefulSamples && $strictDistanceMatch) {
                    $candidate = true;
                }

                if ($candidate === false && $hasUsefulSamples && $strictDistanceMatch === false && $softDistanceMatch) {
                    $softDistanceEligible[$key] = true;
                }
            }

            if ($candidate === false && $features['dominantOutsideHome']) {
                $candidate = true;
            }

            if ($candidate === false && $features['hotelPoiSignal'] && $features['hasStaypointDwell'] && $softDistanceMatch) {
                $candidate = true;
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

        $this->applyLowSampleBridging(
            $isAwayCandidate,
            $keys,
            $metadata,
            fn (string $key): int => $this->minItemsPerDay,
        );

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

            if (
                $features['hasGpsAnchors'] === false
                && $features['transitHeavy'] === false
                && ($features['hasStaypointDwell'] ?? false) === false
            ) {
                $isAwayCandidate[$key] = false;
            }
        }

        $initialRuns = $this->collectRuns($keys, $isAwayCandidate, $indexByKey, $days);

        $longRunMask = [];
        foreach ($initialRuns as $run) {
            $summary = $this->summarizeRun($run, $metadata);

            if ($summary['awayDays'] < 10 || $summary['averagePhotoCount'] < 2.0) {
                continue;
            }

            foreach ($run as $runKey) {
                $longRunMask[$runKey] = true;
            }

            $startIndex = $indexByKey[$run[0]] ?? null;
            $endIndex   = $indexByKey[$run[count($run) - 1]] ?? null;

            if ($startIndex === null || $endIndex === null) {
                continue;
            }

            if ($endIndex < $startIndex) {
                [$startIndex, $endIndex] = [$endIndex, $startIndex];
            }

            for ($i = $startIndex; $i <= $endIndex; ++$i) {
                $longRunMask[$keys[$i]] = true;
            }
        }

        if ($longRunMask !== []) {
            foreach ($longRunMask as $runKey => $_) {
                if (($isAwayCandidate[$runKey] ?? false) === true) {
                    continue;
                }

                if (($softDistanceEligible[$runKey] ?? false) === false) {
                    continue;
                }

                $features = $metadata[$runKey];
                if ($features['dominantInsideHome']) {
                    continue;
                }

                if (
                    $features['hasGpsAnchors'] === false
                    && $features['transitHeavy'] === false
                    && ($features['hasStaypointDwell'] ?? false) === false
                ) {
                    continue;
                }

                $isAwayCandidate[$runKey] = true;
            }

            $this->applyLowSampleBridging(
                $isAwayCandidate,
                $keys,
                $metadata,
                function (string $key) use ($longRunMask): int {
                    return ($longRunMask[$key] ?? false) ? 2 : $this->minItemsPerDay;
                },
            );

            $this->promoteTransitAdjacency($isAwayCandidate, $keys, $metadata);

            foreach ($keys as $key) {
                if (($isAwayCandidate[$key] ?? false) === false) {
                    continue;
                }

                $features = $metadata[$key];

                if ($features['dominantInsideHome']) {
                    $isAwayCandidate[$key] = false;
                    continue;
                }

                if (
                    $features['hasGpsAnchors'] === false
                    && $features['transitHeavy'] === false
                    && ($features['hasStaypointDwell'] ?? false) === false
                ) {
                    $isAwayCandidate[$key] = false;
                }
            }
        }

        return $this->collectRuns($keys, $isAwayCandidate, $indexByKey, $days);
    }

    /**
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int,valid_from?:int|null,valid_until?:int|null}>} $home
     *
     * @return array{strict: float, soft: float}
     */
    private function determineEffectiveMinAwayDistanceBounds(array $home): array
    {
        if ($this->minAwayDistanceProfiles === []) {
            return [$this->minAwayDistanceKm, $this->minAwayDistanceKm];
        }

        $centers = HomeBoundaryHelper::centers($home);
        if ($centers === []) {
            return [$this->minAwayDistanceKm, $this->minAwayDistanceKm];
        }

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

        $strict = $this->minAwayDistanceKm;
        $soft   = $this->minAwayDistanceKm;

        foreach ($this->minAwayDistanceProfiles as $profile) {
            if ($this->matchesDistanceProfile($profile, $centerCount, $totalMembers, $primaryRadius, $primaryDensity, $primaryCountry, false)) {
                $strict = min($strict, $profile['distance_km']);
            }

            if ($this->matchesDistanceProfile($profile, $centerCount, $totalMembers, $primaryRadius, $primaryDensity, $primaryCountry, true)) {
                $soft = min($soft, $profile['distance_km']);
            }
        }

        return [$strict, $soft];
    }

    /**
     * @param array{distance_km:float,min_center_count?:int,min_total_member_count?:int,max_primary_radius_km?:float,min_primary_density?:float,countries?:list<string>} $profile
     * @param bool                                                                                                                                   $ignoreMemberFloor
     */
    private function matchesDistanceProfile(
        array $profile,
        int $centerCount,
        int $totalMembers,
        float $primaryRadius,
        float $primaryDensity,
        ?string $primaryCountry,
        bool $ignoreMemberFloor,
    ): bool {
        $minCenterCount = $profile['min_center_count'] ?? null;
        if (is_int($minCenterCount) && $minCenterCount > 0 && $centerCount < $minCenterCount) {
            return false;
        }

        $minTotalMembers = $profile['min_total_member_count'] ?? null;
        if (!$ignoreMemberFloor && is_int($minTotalMembers) && $minTotalMembers > 0 && $totalMembers < $minTotalMembers) {
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

    /**
     * @param list<string> $keys
     * @param array<string, bool> $isAwayCandidate
     * @param array<string, int> $indexByKey
     * @param array<string, array<string, mixed>> $days
     *
     * @return list<list<string>>
     */
    private function collectRuns(array $keys, array $isAwayCandidate, array $indexByKey, array $days): array
    {
        $runs = [];
        $run  = [];

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
     * @param list<string> $run
     * @param array<string, array{photoCount:int}> $metadata
     *
     * @return array{awayDays:int, averagePhotoCount:float}
     */
    private function summarizeRun(array $run, array $metadata): array
    {
        $awayDays    = count($run);
        $totalPhotos = 0;

        foreach ($run as $key) {
            $totalPhotos += $metadata[$key]['photoCount'] ?? 0;
        }

        return [
            'awayDays'           => $awayDays,
            'averagePhotoCount'  => $awayDays > 0 ? $totalPhotos / $awayDays : 0.0,
        ];
    }

    /**
     * @param array<string, bool> $isAwayCandidate
     * @param list<string>        $keys
     * @param array<string, array{photoCount:int,hasGpsAnchors:bool,transitHeavy:bool}> $metadata
     * @param callable(string, int):int $thresholdProvider
     */
    private function applyLowSampleBridging(
        array &$isAwayCandidate,
        array $keys,
        array $metadata,
        callable $thresholdProvider,
    ): void {
        $countKeys = count($keys);

        for ($i = 0; $i < $countKeys; ++$i) {
            $key = $keys[$i];

            if ($isAwayCandidate[$key] ?? false) {
                continue;
            }

            $prevIsAway = $i > 0 && ($isAwayCandidate[$keys[$i - 1]] ?? false);
            $nextIsAway = $i + 1 < $countKeys && ($isAwayCandidate[$keys[$i + 1]] ?? false);

            if ($prevIsAway === false || $nextIsAway === false) {
                continue;
            }

            $threshold = $thresholdProvider($key, $i);

            if (
                ($metadata[$key]['photoCount'] ?? 0) < $threshold
                && (
                    ($metadata[$key]['hasGpsAnchors'] ?? false)
                    || ($metadata[$key]['transitHeavy'] ?? false)
                    || ($metadata[$key]['hasStaypointDwell'] ?? false)
                )
            ) {
                $isAwayCandidate[$key] = true;
            }
        }
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function hasStaypointDwell(array $summary): bool
    {
        $staypoints = $summary['staypoints'] ?? [];
        if (is_array($staypoints)) {
            foreach ($staypoints as $staypoint) {
                if (!is_array($staypoint)) {
                    continue;
                }

                $dwell = $this->extractDwellSeconds($staypoint);
                if ($dwell !== null && $dwell >= self::MIN_STAYPOINT_BRIDGE_DWELL_SECONDS) {
                    return true;
                }
            }
        }

        $dominantStaypoints = $summary['dominantStaypoints'] ?? [];
        if (is_array($dominantStaypoints)) {
            foreach ($dominantStaypoints as $staypoint) {
                if (!is_array($staypoint)) {
                    continue;
                }

                $dwell = $this->extractDwellSeconds($staypoint);
                if ($dwell !== null && $dwell >= self::MIN_STAYPOINT_BRIDGE_DWELL_SECONDS) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $staypoint
     */
    private function extractDwellSeconds(array $staypoint): ?int
    {
        $dwell = $staypoint['dwell'] ?? ($staypoint['dwellSeconds'] ?? null);

        if (is_int($dwell) || is_float($dwell) || is_numeric($dwell)) {
            return (int) $dwell;
        }

        return null;
    }

    /**
     * @param array<string, bool>                                  $isAwayCandidate
     * @param list<string>                                         $keys
     * @param array<string, array{transitHeavy:bool}> $metadata
     */
    private function promoteTransitAdjacency(array &$isAwayCandidate, array $keys, array $metadata): void
    {
        $countKeys = count($keys);

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
    }

    /**
     * @param array<string, mixed> $features
     */
    private function decideInitialAwayCandidate(
        array $features,
        bool $strictDistanceMatch,
        bool $softDistanceMatch,
        float $strictMinAwayDistanceKm,
    ): bool {
        if ($features['centroidBeyondHome']) {
            return true;
        }

        $centroidDistance = $features['centroidDistanceKm'];
        $centroidRadius   = $features['centroidRadiusKm'];

        if ($centroidDistance !== null) {
            $radiusThreshold = $centroidRadius !== null
                ? max($strictMinAwayDistanceKm, $centroidRadius)
                : $strictMinAwayDistanceKm;

            if ($centroidDistance > $radiusThreshold) {
                return true;
            }
        }

        if ($features['transportSignal']) {
            if ($centroidDistance !== null && $centroidRadius !== null && $centroidDistance > $centroidRadius) {
                return true;
            }

            if ($strictDistanceMatch) {
                return true;
            }

            if ($features['baseAway'] && $softDistanceMatch) {
                return true;
            }
        }

        if ($features['hotelPoiSignal']) {
            if ($centroidDistance !== null && $centroidRadius !== null && $centroidDistance > $centroidRadius) {
                return true;
            }

            if ($features['baseAway'] && ($features['hasStaypointDwell'] || $features['photoCount'] >= $this->minItemsPerDay)) {
                return true;
            }
        }

        if ($features['baseAway'] && $strictDistanceMatch) {
            return true;
        }

        if ($features['baseAway'] && $features['hasStaypointDwell']) {
            return true;
        }

        if ($features['baseAway']) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array{lat:float,lon:float}|null
     */
    private function resolveDayCentroid(array $summary): ?array
    {
        $members = $summary['gpsMembers'] ?? [];
        if (is_array($members) && $members !== []) {
            $centroid = MediaMath::centroid($members);

            return ['lat' => $centroid['lat'], 'lon' => $centroid['lon']];
        }

        $dominantStaypoints = $summary['dominantStaypoints'] ?? [];
        if (is_array($dominantStaypoints) && $dominantStaypoints !== []) {
            $primary = $dominantStaypoints[0];

            if (isset($primary['lat'], $primary['lon'])) {
                return ['lat' => (float) $primary['lat'], 'lon' => (float) $primary['lon']];
            }
        }

        $baseLocation = $summary['baseLocation'] ?? null;
        if (is_array($baseLocation) && isset($baseLocation['lat'], $baseLocation['lon'])) {
            return ['lat' => (float) $baseLocation['lat'], 'lon' => (float) $baseLocation['lon']];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function hasHotelPoiSignal(array $summary): bool
    {
        $tourismHits = (int) ($summary['tourismHits'] ?? 0);
        if ($tourismHits <= 0) {
            return false;
        }

        if ($this->hasStaypointDwell($summary)) {
            return true;
        }

        $staypointCounts = $summary['staypointCounts'] ?? null;
        if (is_array($staypointCounts) && $staypointCounts !== []) {
            return true;
        }

        $poiDensity = (float) ($summary['poiDensity'] ?? 0.0);
        if ($poiDensity >= 0.25) {
            return true;
        }

        return false;
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
