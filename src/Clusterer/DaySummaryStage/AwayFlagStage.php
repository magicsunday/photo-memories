<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\DaySummaryStage;

use DateInterval;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Contract\BaseLocationResolverInterface;
use MagicSunday\Memories\Clusterer\Contract\DaySummaryStageInterface;
use MagicSunday\Memories\Clusterer\Contract\TimezoneResolverInterface;
use MagicSunday\Memories\Clusterer\Support\HomeBoundaryHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function array_keys;
use function count;
use function max;
use function min;

/**
 * Post-processes away flags for day summaries.
 */
final readonly class AwayFlagStage implements DaySummaryStageInterface
{
    private int $nightWindowDurationHours;

    public function __construct(
        private TimezoneResolverInterface $timezoneResolver,
        private BaseLocationResolverInterface $baseLocationResolver,
        private float $nextDayDominantDistanceFactor = 1.5,
        private int $nightWindowStartHour = 22,
        private int $nightWindowEndHour = 6,
    ) {
        if ($this->nextDayDominantDistanceFactor <= 1.0) {
            throw new \InvalidArgumentException('nextDayDominantDistanceFactor must be greater than 1.0.');
        }

        if ($this->nightWindowStartHour < 0 || $this->nightWindowStartHour > 23) {
            throw new \InvalidArgumentException('nightWindowStartHour must be between 0 and 23.');
        }

        if ($this->nightWindowEndHour < 0 || $this->nightWindowEndHour > 23) {
            throw new \InvalidArgumentException('nightWindowEndHour must be between 0 and 23.');
        }

        $duration = $this->nightWindowEndHour - $this->nightWindowStartHour;
        if ($duration <= 0) {
            $duration += 24;
        }

        if ($duration <= 0) {
            throw new \InvalidArgumentException('night window duration must be positive.');
        }

        $this->nightWindowDurationHours = $duration;
    }

    public function process(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        $keys = array_keys($days);
        $nightAwayFlags = [];
        foreach ($keys as $index => $key) {
            $nextKey     = $keys[$index + 1] ?? null;
            $summary     = $days[$key];
            $nextSummary = $nextKey !== null ? $days[$nextKey] : null;

            $timezone                   = $this->timezoneResolver->resolveSummaryTimezone($summary, $home);
            $baseLocation               = $this->baseLocationResolver->resolve($summary, $nextSummary, $home, $timezone);
            $days[$key]['baseLocation'] = $baseLocation;

            if ($baseLocation !== null && HomeBoundaryHelper::isBeyondHome($home, $baseLocation['lat'], $baseLocation['lon'], true)) {
                $days[$key]['baseAway'] = true;

                if (($days[$key]['isAwayCandidate'] ?? false) === false) {
                    $window = $this->createNightWindow($summary['date'], $timezone);
                    if ($window !== null && !$this->hasStaypointCoveringWindow($summary['staypoints'] ?? [], $window['start'], $window['end'])) {
                        $days[$key]['isAwayCandidate'] = true;
                    }
                }
            }

            if ($summary['gpsMembers'] !== [] && HomeBoundaryHelper::hasCoordinateSamples($summary['gpsMembers'])) {
                $centroid     = MediaMath::centroid($summary['gpsMembers']);
                $isBeyondHome = HomeBoundaryHelper::isBeyondHome($home, $centroid['lat'], $centroid['lon'], true);

                if ($isBeyondHome) {
                    $days[$key]['awayByDistance'] = true;
                }
            } elseif ($summary['avgDistanceKm'] > HomeBoundaryHelper::primaryRadius($home)) {
                $days[$key]['awayByDistance'] = true;
            }

            if ($this->shouldFlagByNextDominantStaypoint($summary, $nextSummary, $home, $timezone)) {
                $nightAwayFlags[$key] = true;
            }
        }

        $baseFlags     = [];
        $distanceFlags = [];
        foreach ($keys as $key) {
            $summary             = $days[$key];
            $baseFlags[$key]     = $summary['baseAway'];
            $distanceFlags[$key] = $summary['awayByDistance'];
        }

        $baseFlags     = $this->applyMorphologicalClosing($baseFlags);
        $distanceFlags = $this->propagateDistanceRuns($distanceFlags);

        foreach ($keys as $key) {
            $days[$key]['baseAway'] = $baseFlags[$key] || $distanceFlags[$key];
        }

        $combinedFlags = [];
        foreach ($keys as $key) {
            $combinedFlags[$key] = $days[$key]['baseAway'];
        }

        $combinedFlags = $this->applyMorphologicalClosing($combinedFlags);
        $combinedFlags = $this->inheritSyntheticAwayFlags($combinedFlags, $keys, $days);

        foreach ($nightAwayFlags as $key => $flag) {
            if ($flag === true) {
                $combinedFlags[$key] = true;
            }
        }

        foreach ($keys as $key) {
            $days[$key]['baseAway'] = $combinedFlags[$key];
        }

        return $days;
    }

    private function shouldFlagByNextDominantStaypoint(array $summary, ?array $nextSummary, array $home, \DateTimeZone $timezone): bool
    {
        if ($nextSummary === null) {
            return false;
        }

        $dominantStaypoints = $nextSummary['dominantStaypoints'] ?? [];
        if ($dominantStaypoints === []) {
            return false;
        }

        $dominant = $dominantStaypoints[0];
        if (!isset($dominant['lat'], $dominant['lon'])) {
            return false;
        }

        $dominantTimestamp = null;
        $dominantStart     = isset($dominant['start']) ? (int) $dominant['start'] : null;
        $dominantEnd       = isset($dominant['end']) ? (int) $dominant['end'] : null;

        if ($dominantStart !== null && $dominantStart > 0) {
            $dominantTimestamp = $dominantStart;
        } elseif ($dominantEnd !== null && $dominantEnd > 0) {
            $dominantTimestamp = $dominantEnd;
        }

        $nearest = HomeBoundaryHelper::nearestCenter(
            $home,
            (float) $dominant['lat'],
            (float) $dominant['lon'],
            $dominantTimestamp,
        );
        if ($nearest['distance_km'] <= $nearest['radius_km'] * $this->nextDayDominantDistanceFactor) {
            return false;
        }

        $window = $this->createNightWindow($summary['date'], $timezone);
        if ($window === null) {
            return false;
        }

        if ($this->hasHomeStaypointInWindow($summary['staypoints'] ?? [], $window['start'], $window['end'], $home)) {
            return false;
        }

        if ($this->hasHomeStaypointInWindow($nextSummary['staypoints'] ?? [], $window['start'], $window['end'], $home)) {
            return false;
        }

        return true;
    }

    private function createNightWindow(string $date, \DateTimeZone $timezone): ?array
    {
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', sprintf('%s %02d:00:00', $date, $this->nightWindowStartHour), $timezone);
        if ($start === false) {
            return null;
        }

        $end = $start->add(new DateInterval('PT' . $this->nightWindowDurationHours . 'H'));

        return [
            'start' => $start,
            'end'   => $end,
        ];
    }

    /**
     * @param list<array{start:int,end:int}> $staypoints
     */
    private function hasStaypointCoveringWindow(array $staypoints, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): bool
    {
        if ($staypoints === []) {
            return false;
        }

        $startTs       = $windowStart->getTimestamp();
        $endTs         = $windowEnd->getTimestamp();
        $windowSeconds = max(0, $endTs - $startTs);
        $requiredCover = (int) ($windowSeconds * 0.75);

        foreach ($staypoints as $staypoint) {
            $stayStart = (int) ($staypoint['start'] ?? 0);
            $stayEnd   = (int) ($staypoint['end'] ?? 0);

            if ($stayStart === 0 || $stayEnd === 0) {
                continue;
            }

            $overlapStart = max($stayStart, $startTs);
            $overlapEnd   = min($stayEnd, $endTs);

            if ($overlapEnd <= $overlapStart) {
                continue;
            }

            if (($overlapEnd - $overlapStart) >= $requiredCover) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{lat:float,lon:float,start:int,end:int}> $staypoints
     */
    private function hasHomeStaypointInWindow(array $staypoints, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd, array $home): bool
    {
        if ($staypoints === []) {
            return false;
        }

        $startTs = $windowStart->getTimestamp();
        $endTs   = $windowEnd->getTimestamp();

        foreach ($staypoints as $staypoint) {
            $stayStart = (int) ($staypoint['start'] ?? 0);
            $stayEnd   = (int) ($staypoint['end'] ?? 0);

            if ($stayEnd < $startTs || $stayStart > $endTs) {
                continue;
            }

            if (!isset($staypoint['lat'], $staypoint['lon'])) {
                continue;
            }

            $overlapStart = max($stayStart > 0 ? $stayStart : $startTs, $startTs);
            $overlapEnd   = min($stayEnd > 0 ? $stayEnd : $endTs, $endTs);

            $timestamp = null;
            if ($overlapStart <= $overlapEnd) {
                $timestamp = $overlapStart;
            } elseif ($stayStart > 0) {
                $timestamp = $stayStart;
            } elseif ($stayEnd > 0) {
                $timestamp = $stayEnd;
            }

            $nearest = HomeBoundaryHelper::nearestCenter(
                $home,
                (float) $staypoint['lat'],
                (float) $staypoint['lon'],
                $timestamp,
            );
            if ($nearest['distance_km'] <= $nearest['radius_km']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, bool> $flags
     *
     * @return array<string, bool>
     */
    private function applyMorphologicalClosing(array $flags): array
    {
        $keys  = array_keys($flags);
        $count = count($keys);

        if ($count < 3) {
            return $flags;
        }

        for ($i = 1; $i < $count - 1; ++$i) {
            $prev = $flags[$keys[$i - 1]];
            $curr = $flags[$keys[$i]];
            $next = $flags[$keys[$i + 1]];

            if ($curr === false && $prev === true && $next === true) {
                $flags[$keys[$i]] = true;
            }
        }

        return $flags;
    }

    /**
     * @param array<string, bool> $flags
     *
     * @return array<string, bool>
     */
    private function propagateDistanceRuns(array $flags): array
    {
        $keys  = array_keys($flags);
        $count = count($keys);

        $first = null;
        $last  = null;
        foreach ($keys as $index => $key) {
            if ($flags[$key] === true) {
                $first ??= $index;
                $last = $index;
            }
        }

        if ($first !== null && $last !== null && $last > $first) {
            for ($i = $first; $i <= $last; ++$i) {
                $flags[$keys[$i]] = true;
            }
        }

        return $this->applyMorphologicalClosing($flags);
    }

    /**
     * @param array<string, bool>                    $flags
     * @param list<string>                           $orderedKeys
     * @param array<string, array{isSynthetic:bool}> $days
     *
     * @return array<string, bool>
     */
    private function inheritSyntheticAwayFlags(array $flags, array $orderedKeys, array $days): array
    {
        $count = count($orderedKeys);

        for ($i = 0; $i < $count; ++$i) {
            $key     = $orderedKeys[$i];
            $summary = $days[$key];

            if ($summary['isSynthetic'] === false) {
                continue;
            }

            $prev = $i > 0 ? $flags[$orderedKeys[$i - 1]] : null;
            $next = $i + 1 < $count ? $flags[$orderedKeys[$i + 1]] : null;

            if ($prev === true || $next === true) {
                $flags[$key] = true;
            }
        }

        return $flags;
    }
}
