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
use function count;

/**
 * Post-processes away flags for day summaries.
 */
final class AwayFlagStage implements DaySummaryStageInterface
{
    public function __construct(
        private readonly TimezoneResolverInterface $timezoneResolver,
        private readonly BaseLocationResolverInterface $baseLocationResolver,
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
     * @param array<string, bool> $flags
     * @param list<string>        $orderedKeys
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
