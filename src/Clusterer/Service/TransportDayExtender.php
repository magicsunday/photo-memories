<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Service;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_reverse;
use function array_unshift;
use function count;
use function in_array;
use function is_array;
use function is_float;
use function is_int;

/**
 * Adds potential transport days to a vacation run.
 */
final class TransportDayExtender
{
    use ConsecutiveDaysTrait;

    private const int MIN_STAYPOINT_BRIDGE_DWELL_SECONDS = 7200;

    public function __construct(
        private float $transitRatioThreshold = 0.65,
        private float $transitSpeedThreshold = 100.0,
        private int $leanPhotoThreshold = 2,
        private int $maxLeanBridgeDays = 1,
        private float $minLeanBridgeDistanceKm = 60.0,
    ) {
        if ($this->transitRatioThreshold < 0.0 || $this->transitRatioThreshold > 1.0) {
            throw new InvalidArgumentException('transitRatioThreshold must be between 0.0 and 1.0.');
        }

        if ($this->transitSpeedThreshold <= 0.0) {
            throw new InvalidArgumentException('transitSpeedThreshold must be greater than 0.');
        }

        if ($this->leanPhotoThreshold < 0) {
            throw new InvalidArgumentException('leanPhotoThreshold must be zero or positive.');
        }

        if ($this->maxLeanBridgeDays < 0) {
            throw new InvalidArgumentException('maxLeanBridgeDays must be zero or positive.');
        }

        if ($this->minLeanBridgeDistanceKm < 0.0) {
            throw new InvalidArgumentException('minLeanBridgeDistanceKm must be zero or positive.');
        }
    }

    /**
     * @param list<string>                                                                                                                                                                                                                                                                                                                                                                                     $run
     * @param list<string>                                                                                                                                                                                                                                                                                                                                                                                     $orderedKeys
     * @param array<string, int>                                                                                                                                                                                                                                                                                                                                                                               $indexByKey
     * @param array<string, array{hasAirportPoi: bool, hasHighSpeedTransit: bool, isSynthetic: bool, dominantStaypoints?: list<array{key: string, lat: float, lon: float, start: int, end: int, dwellSeconds: int, memberCount: int}>, transitRatio?: float, avgSpeedKmh?: float, maxSpeedKmh?: float, photoCount?: int, baseLocation?: array{lat: float|int, lon: float|int}|null, gpsMembers?: list<Media>}> $days
     *
     * @return list<string>
     */
    public function extend(array $run, array $orderedKeys, array $indexByKey, array $days): array
    {
        if ($run === []) {
            return $run;
        }

        $extended = $run;

        $firstKey   = $run[0];
        $firstIndex = $indexByKey[$firstKey] ?? null;
        $firstLean  = $this->countLeanDaysAtBoundary($extended, $days, true);
        if ($firstIndex !== null && $firstIndex > 0) {
            $candidateKey = $orderedKeys[$firstIndex - 1];
            if (
                !in_array($candidateKey, $extended, true)
                && $this->shouldExtendWithDay($candidateKey, $firstKey, $days, $firstLean)
                && $this->areSequentialDays($candidateKey, $firstKey, $days)
            ) {
                array_unshift($extended, $candidateKey);
            }
        }

        $lastKey      = $run[count($run) - 1];
        $lastIndex    = $indexByKey[$lastKey] ?? null;
        $lastLean     = $this->countLeanDaysAtBoundary($extended, $days, false);
        $orderedCount = count($orderedKeys);
        if ($lastIndex !== null && $lastIndex + 1 < $orderedCount) {
            $candidateKey = $orderedKeys[$lastIndex + 1];
            if (
                !in_array($candidateKey, $extended, true)
                && $this->shouldExtendWithDay($candidateKey, $lastKey, $days, $lastLean)
                && $this->areSequentialDays($lastKey, $candidateKey, $days)
            ) {
                $extended[] = $candidateKey;
            }
        }

        return $extended;
    }

    /**
     * @param array<string, array{hasAirportPoi:bool,hasHighSpeedTransit:bool,isSynthetic:bool,dominantStaypoints?:list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int}>,transitRatio?:float,avgSpeedKmh?:float,maxSpeedKmh?:float,photoCount?:int}> $days
     */
    private function shouldExtendWithDay(string $candidateKey, string $anchorKey, array $days, int $leanStreakAtBoundary): bool
    {
        $candidate = $days[$candidateKey] ?? null;
        if ($candidate === null) {
            return false;
        }

        $isLeanCandidate = $this->isLeanDay($candidate);
        if ($isLeanCandidate) {
            if ($this->maxLeanBridgeDays === 0) {
                return false;
            }

            if ($leanStreakAtBoundary + 1 > $this->maxLeanBridgeDays) {
                return false;
            }

            $anchor = $days[$anchorKey] ?? null;
            if ($anchor === null) {
                return false;
            }

            if ($this->isLeanDay($anchor)) {
                return false;
            }

            if ($this->isTransitHeavy($candidate)) {
                return true;
            }

            if ($this->hasStrongStaypoint($candidate)) {
                return true;
            }

            $interDayDistance = $this->computeInterDayDistance($candidate, $anchor);

            return $interDayDistance !== null && $interDayDistance > $this->minLeanBridgeDistanceKm;
        }

        if ($candidate['hasAirportPoi'] || $candidate['hasHighSpeedTransit']) {
            return true;
        }

        return $this->isTransitHeavy($candidate);
    }

    /**
     * @param array{staypoints?:list<array{dwell?:int|float,dwellSeconds?:int|float}>,dominantStaypoints?:list<array{dwell?:int|float,dwellSeconds?:int|float}>} $summary
     */
    private function hasStrongStaypoint(array $summary): bool
    {
        $staypoints = $summary['staypoints'] ?? [];
        foreach ($staypoints as $staypoint) {
            $dwell = $this->extractDwellSeconds($staypoint);
            if ($dwell !== null && $dwell >= self::MIN_STAYPOINT_BRIDGE_DWELL_SECONDS) {
                return true;
            }
        }

        $dominantStaypoints = $summary['dominantStaypoints'] ?? [];
        foreach ($dominantStaypoints as $staypoint) {
            $dwell = $this->extractDwellSeconds($staypoint);
            if ($dwell !== null && $dwell >= self::MIN_STAYPOINT_BRIDGE_DWELL_SECONDS) {
                return true;
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

        if (is_int($dwell) || is_float($dwell)) {
            return (int) $dwell;
        }

        return null;
    }

    /**
     * @param array{hasAirportPoi:bool,hasHighSpeedTransit:bool,isSynthetic:bool,dominantStaypoints?:list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int}>,transitRatio:float,avgSpeedKmh:float,maxSpeedKmh:float,photoCount?:int} $summary
     */
    private function isTransitHeavy(array $summary): bool
    {
        if ($summary['hasHighSpeedTransit'] === true) {
            return true;
        }

        $ratio = $summary['transitRatio'];
        if ($ratio >= $this->transitRatioThreshold) {
            return true;
        }

        $avgSpeed = $summary['avgSpeedKmh'];
        if ($avgSpeed >= $this->transitSpeedThreshold) {
            return true;
        }

        $maxSpeed = $summary['maxSpeedKmh'];

        return $maxSpeed >= $this->transitSpeedThreshold;
    }

    /**
     * @param array{hasAirportPoi:bool,hasHighSpeedTransit:bool,isSynthetic:bool,dominantStaypoints?:list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int}>,photoCount?:int} $summary
     */
    private function isLeanDay(array $summary): bool
    {
        $dominantStaypoints = $summary['dominantStaypoints'] ?? [];
        if ($dominantStaypoints !== []) {
            return false;
        }

        return ($summary['photoCount'] ?? 0) <= $this->leanPhotoThreshold;
    }

    /**
     * @param list<string>                                                                                                                                                                                                                                                                    $run
     * @param array<string, array{hasAirportPoi:bool,hasHighSpeedTransit:bool,isSynthetic:bool,dominantStaypoints?:list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int}>,transitRatio?:float,avgSpeedKmh?:float,maxSpeedKmh?:float,photoCount?:int}> $days
     */
    private function countLeanDaysAtBoundary(array $run, array $days, bool $fromStart): int
    {
        if ($run === []) {
            return 0;
        }

        $keys  = $fromStart ? $run : array_reverse($run);
        $count = 0;

        foreach ($keys as $key) {
            $summary = $days[$key] ?? null;
            if ($summary === null) {
                break;
            }

            if ($this->isLeanDay($summary) === false) {
                break;
            }

            ++$count;
        }

        return $count;
    }

    /**
     * @param array{baseLocation?: array{lat: float|int, lon: float|int}|null, dominantStaypoints?: list<array{lat: float, lon: float}>, gpsMembers?: list<Media>} $candidate
     * @param array{baseLocation?: array{lat: float|int, lon: float|int}|null, dominantStaypoints?: list<array{lat: float, lon: float}>, gpsMembers?: list<Media>} $anchor
     */
    private function computeInterDayDistance(array $candidate, array $anchor): ?float
    {
        $candidateCoordinate = $this->resolveReferenceCoordinate($candidate);
        $anchorCoordinate    = $this->resolveReferenceCoordinate($anchor);

        if ($candidateCoordinate === null || $anchorCoordinate === null) {
            return null;
        }

        return MediaMath::haversineDistanceInMeters(
            $candidateCoordinate['lat'],
            $candidateCoordinate['lon'],
            $anchorCoordinate['lat'],
            $anchorCoordinate['lon'],
        ) / 1000.0;
    }

    /**
     * @param array{baseLocation?: array{lat: float|int, lon: float|int}|null, dominantStaypoints?: list<array{lat: float, lon: float, start?: int, end?: int, dwellSeconds?: int, memberCount?: int}>, gpsMembers?: list<Media>} $summary
     *
     * @return array{lat: float, lon: float}|null
     */
    private function resolveReferenceCoordinate(array $summary): ?array
    {
        $baseLocation = $summary['baseLocation'] ?? null;
        if (is_array($baseLocation)) {
            return [
                'lat' => (float) $baseLocation['lat'],
                'lon' => (float) $baseLocation['lon'],
            ];
        }

        $dominantStaypoints = $summary['dominantStaypoints'] ?? [];
        if ($dominantStaypoints !== []) {
            $staypoint = $dominantStaypoints[0];

            return [
                'lat' => $staypoint['lat'],
                'lon' => $staypoint['lon'],
            ];
        }

        $gpsMembers = $summary['gpsMembers'] ?? [];
        if ($gpsMembers !== []) {
            $centroid = MediaMath::centroid($gpsMembers);

            return [
                'lat' => $centroid['lat'],
                'lon' => $centroid['lon'],
            ];
        }

        return null;
    }

    /**
     * @param string                                 $previous
     * @param string                                 $current
     * @param array<string, array{isSynthetic:bool}> $days
     *
     * @return bool
     *
     * @throws DateMalformedStringException
     */
    public function areSequentialDays(string $previous, string $current, array $days): bool
    {
        return $this->checkSequentialDays($previous, $current, $days);
    }

    /**
     * @param string                                 $previous
     * @param string                                 $current
     * @param array<string, array{isSynthetic:bool}> $days
     *
     * @return bool
     *
     * @throws DateMalformedStringException
     */
    private function checkSequentialDays(string $previous, string $current, array $days): bool
    {
        if ($this->isNextDay($previous, $current)) {
            return true;
        }

        $timezone = new DateTimeZone('UTC');
        $start    = DateTimeImmutable::createFromFormat('!Y-m-d', $previous, $timezone);
        $end      = DateTimeImmutable::createFromFormat('!Y-m-d', $current, $timezone);

        if ($start === false || $end === false || $start > $end) {
            return false;
        }

        $cursor = $start->modify('+1 day');
        while ($cursor < $end) {
            $key     = $cursor->format('Y-m-d');
            $summary = $days[$key] ?? null;
            if ($summary === null) {
                return false;
            }

            if ($summary['isSynthetic'] === false) {
                return false;
            }

            $cursor = $cursor->modify('+1 day');
        }

        return true;
    }
}
