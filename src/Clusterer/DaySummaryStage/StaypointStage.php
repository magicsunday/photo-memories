<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\DaySummaryStage;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\DaySummaryStageInterface;
use MagicSunday\Memories\Clusterer\Support\StaypointIndex;
use MagicSunday\Memories\Entity\Media;

use function array_slice;
use function count;
use function usort;

/**
 * Enriches day summaries with staypoint-derived aggregates.
 */
final readonly class StaypointStage implements DaySummaryStageInterface
{
    public function __construct(
        private int $dominantStaypointLimit = 3,
    ) {
        if ($this->dominantStaypointLimit < 0) {
            throw new InvalidArgumentException('dominantStaypointLimit must be zero or greater.');
        }
    }

    public function process(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        foreach ($days as &$summary) {
            $staypoints = $summary['staypoints'];

            $index = StaypointIndex::build($summary['date'], $staypoints, $summary['members']);

            $summary['staypointIndex']     = $index;
            $summary['staypointCounts']    = $index->getCounts();
            $summary['dominantStaypoints'] = $this->determineDominantStaypoints(
                $summary['date'],
                $staypoints,
                $summary['staypointCounts'],
            );
            $summary['transitRatio'] = $this->calculateTransitRatio($summary, $staypoints);
            $summary['poiDensity']   = $this->calculatePoiDensity(
                (int) $summary['poiSamples'],
                (int) $summary['photoCount'],
                count($staypoints),
            );
        }

        unset($summary);

        return $days;
    }

    /**
     * @param list<array{lat:float,lon:float,start:int,end:int,dwell:int}> $staypoints
     * @param array<string, int>                                           $counts
     *
     * @return list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int}>
     */
    private function determineDominantStaypoints(string $date, array $staypoints, array $counts): array
    {
        if ($staypoints === []) {
            return [];
        }

        $dominant = [];
        foreach ($staypoints as $staypoint) {
            $key = StaypointIndex::createKeyFromStaypoint($date, $staypoint);

            $dominant[] = [
                'key'          => $key,
                'lat'          => (float) $staypoint['lat'],
                'lon'          => (float) $staypoint['lon'],
                'start'        => (int) $staypoint['start'],
                'end'          => (int) $staypoint['end'],
                'dwellSeconds' => (int) $staypoint['dwell'],
                'memberCount'  => $counts[$key] ?? 0,
            ];
        }

        usort($dominant, static function (array $a, array $b): int {
            $dwellComparison = $b['dwellSeconds'] <=> $a['dwellSeconds'];
            if ($dwellComparison !== 0) {
                return $dwellComparison;
            }

            $memberComparison = $b['memberCount'] <=> $a['memberCount'];
            if ($memberComparison !== 0) {
                return $memberComparison;
            }

            return $a['key'] <=> $b['key'];
        });

        if ($this->dominantStaypointLimit > 0 && count($dominant) > $this->dominantStaypointLimit) {
            $dominant = array_slice($dominant, 0, $this->dominantStaypointLimit);
        }

        return $dominant;
    }

    /**
     * @param array<string, mixed>                                         $summary
     * @param list<array{lat:float,lon:float,start:int,end:int,dwell:int}> $staypoints
     */
    private function calculateTransitRatio(array $summary, array $staypoints): float
    {
        $firstMedia = $summary['firstGpsMedia'] ?? null;
        $lastMedia  = $summary['lastGpsMedia'] ?? null;

        if (!$firstMedia instanceof Media || !$lastMedia instanceof Media) {
            return 0.0;
        }

        $start = $firstMedia->getTakenAt();
        $end   = $lastMedia->getTakenAt();

        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return 0.0;
        }

        $span = $end->getTimestamp() - $start->getTimestamp();
        if ($span <= 0) {
            return 0.0;
        }

        $staypointSeconds = 0;
        foreach ($staypoints as $staypoint) {
            $staypointSeconds += (int) $staypoint['dwell'];
        }

        if ($staypointSeconds <= 0) {
            return 1.0;
        }

        if ($staypointSeconds >= $span) {
            return 0.0;
        }

        $transitSeconds = $span - $staypointSeconds;
        if ($transitSeconds <= 0) {
            return 0.0;
        }

        $ratio = $transitSeconds / $span;

        if ($ratio < 0.0) {
            return 0.0;
        }

        if ($ratio > 1.0) {
            return 1.0;
        }

        return $ratio;
    }

    private function calculatePoiDensity(int $poiSamples, int $photoCount, int $staypointCount): float
    {
        if ($poiSamples <= 0) {
            return 0.0;
        }

        if ($staypointCount > 0) {
            $density = $poiSamples / $staypointCount;
        } elseif ($photoCount > 0) {
            $density = $poiSamples / $photoCount;
        } else {
            return 0.0;
        }

        if ($density < 0.0) {
            return 0.0;
        }

        if ($density > 1.0) {
            return 1.0;
        }

        return $density;
    }
}
