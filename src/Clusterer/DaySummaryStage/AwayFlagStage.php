<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\DaySummaryStage;

use MagicSunday\Memories\Clusterer\Contract\BaseLocationResolverInterface;
use MagicSunday\Memories\Clusterer\Contract\DaySummaryStageInterface;
use MagicSunday\Memories\Clusterer\Contract\TimezoneResolverInterface;

use function array_keys;
use function array_slice;
use function count;

/**
 * Post-processes away flags for day summaries.
 */
final readonly class AwayFlagStage implements DaySummaryStageInterface
{
    public function __construct(
        private TimezoneResolverInterface     $timezoneResolver,
        private BaseLocationResolverInterface $baseLocationResolver,
    ) {
    }

    public function process(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        $keys = array_keys($days);
        foreach ($keys as $index => $key) {
            $summary     = &$days[$key];
            $nextKey     = $keys[$index + 1] ?? null;
            $nextSummary = $nextKey !== null ? $days[$nextKey] : null;

            $timezone = $this->timezoneResolver->resolveSummaryTimezone($summary, $home);
            $baseLocation = $this->baseLocationResolver->resolve($summary, $nextSummary, $home, $timezone);
            $summary['baseLocation'] = $baseLocation;

            if ($baseLocation !== null && $baseLocation['distance_km'] > $home['radius_km']) {
                $summary['baseAway'] = true;
            }

            if ($summary['avgDistanceKm'] > $home['radius_km']) {
                $summary['awayByDistance'] = true;
            }

            unset($summary);
        }

        unset($summary);

        $baseFlags     = [];
        $distanceFlags = [];
        foreach ($keys as $key) {
            $summary = $days[$key];
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

        foreach ($keys as $key) {
            $days[$key]['baseAway'] = $combinedFlags[$key];
        }

        return $days;
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

        foreach ($keys as $index => $key) {
            if ($index === 0 || $index === $count - 1) {
                continue;
            }

            $prev = $flags[$keys[$index - 1]];
            $curr = $flags[$key];
            $next = $flags[$keys[$index + 1]];

            if ($curr === false && $prev === true && $next === true) {
                $flags[$key] = true;
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
            foreach (array_slice($keys, $first, $last - $first + 1) as $key) {
                $flags[$key] = true;
            }
        }

        return $this->applyMorphologicalClosing($flags);
    }

    /**
     * @param array<string, bool> $flags
     * @param list<string>        $orderedKeys
     * @param array<string, array{isSynthetic:bool}> $days
     *
     * @return array<string, bool>
     */
    private function inheritSyntheticAwayFlags(array $flags, array $orderedKeys, array $days): array
    {
        $count = count($orderedKeys);

        foreach ($orderedKeys as $index => $key) {
            $summary = $days[$key];

            if ($summary['isSynthetic'] === false) {
                continue;
            }

            $prev = $index > 0 ? $flags[$orderedKeys[$index - 1]] : null;
            $next = $index + 1 < $count ? $flags[$orderedKeys[$index + 1]] : null;

            if ($prev === true || $next === true) {
                $flags[$key] = true;
            }
        }

        return $flags;
    }
}
