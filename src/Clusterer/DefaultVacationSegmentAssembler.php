<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Contract\VacationRunDetectorInterface;
use MagicSunday\Memories\Clusterer\Contract\VacationScoreCalculatorInterface;
use MagicSunday\Memories\Clusterer\Contract\VacationSegmentAssemblerInterface;
use MagicSunday\Memories\Entity\Media;

use function arsort;
use function ceil;
use function count;
use function floor;
use function max;
use function min;
use function round;
use function sort;

/**
 * Coordinates vacation segment detection and scoring.
 */
final class DefaultVacationSegmentAssembler implements VacationSegmentAssemblerInterface
{
    public function __construct(
        private VacationRunDetectorInterface $runDetector,
        private VacationScoreCalculatorInterface $scoreCalculator,
    ) {
    }

    /**
     * @param array<string, array{date:string,members:list<Media>,gpsMembers:list<Media>,maxDistanceKm:float,avgDistanceKm:float,travelKm:float,maxSpeedKmh:float,avgSpeedKmh:float,hasHighSpeedTransit:bool,countryCodes:array<string,true>,timezoneOffsets:array<int,int>,localTimezoneIdentifier:string,localTimezoneOffset:int|null,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,isAwayCandidate:bool,sufficientSamples:bool,spotClusters:list<list<Media>>,spotNoise:list<Media>,spotCount:int,spotNoiseSamples:int,spotDwellSeconds:int,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,staypointIndex:\MagicSunday\Memories\Clusterer\Support\StaypointIndex,staypointCounts:array<string,int>,dominantStaypoints:list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int>>,transitRatio:float,poiDensity:float,cohortPresenceRatio:float,cohortMembers:array<int,int>,baseLocation:array{lat:float,lon:float,distance_km:float,source:string}|null,baseAway:bool,awayByDistance:bool,firstGpsMedia:Media|null,lastGpsMedia:Media|null,isSynthetic:bool}> $days
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null}                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         $home
     *
     * @return list<ClusterDraft>
     */
    public function detectSegments(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        $clusters = [];
        $runs     = $this->runDetector->detectVacationRuns($days, $home);
        foreach ($runs as $run) {
            $dayContext = $this->classifyRunDays($run, $days);
            $draft      = $this->scoreCalculator->buildDraft($run, $days, $home, $dayContext);
            if ($draft instanceof ClusterDraft) {
                $clusters[] = $draft;
            }
        }

        return $clusters;
    }

    /**
     * @param list<string> $dayKeys
     * @param array<string, array{date:string,members:list<Media>,gpsMembers:list<Media>,maxDistanceKm:float,avgDistanceKm:float,travelKm:float,maxSpeedKmh:float,avgSpeedKmh:float,hasHighSpeedTransit:bool,countryCodes:array<string,true>,timezoneOffsets:array<int,int>,localTimezoneIdentifier:string,localTimezoneOffset:int|null,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,isAwayCandidate:bool,sufficientSamples:bool,spotClusters:list<list<Media>>,spotNoise:list<Media>,spotCount:int,spotNoiseSamples:int,spotDwellSeconds:int,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,staypointIndex:\MagicSunday\Memories\Clusterer\Support\StaypointIndex,staypointCounts:array<string,int>,dominantStaypoints:list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int>>,transitRatio:float,poiDensity:float,cohortPresenceRatio:float,cohortMembers:array<int,int>,baseLocation:array{lat:float,lon:float,distance_km:float,source:string}|null,baseAway:bool,awayByDistance:bool,firstGpsMedia:Media|null,lastGpsMedia:Media|null,isSynthetic:bool}> $days
     *
     * @return array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}>
     */
    private function classifyRunDays(array $dayKeys, array $days): array
    {
        if ($dayKeys === []) {
            return [];
        }

        $scores   = [];
        $metadata = [];

        foreach ($dayKeys as $key) {
            if (!isset($days[$key])) {
                continue;
            }

            $summary = $days[$key];
            $score   = $this->calculateCoreScore($summary);
            $duration = $this->calculateDayDuration($summary['members']);

            $scores[$key] = $score;
            $metadata[$key] = [
                'score'    => $score,
                'duration' => $duration,
                'metrics'  => $this->buildCoreMetrics($summary, $score),
            ];
        }

        if ($scores === []) {
            return [];
        }

        arsort($scores);

        $totalDays = count($scores);
        $minCore   = (int) ceil($totalDays * 0.6);
        $maxCore   = (int) floor($totalDays * 0.7);
        if ($maxCore < 1) {
            $maxCore = 1;
        }

        if ($maxCore < $minCore) {
            $maxCore = $minCore;
        }

        if ($maxCore > $totalDays) {
            $maxCore = $totalDays;
        }

        $targetCore = (int) round($totalDays * 0.65);
        if ($targetCore < $minCore) {
            $targetCore = $minCore;
        }

        if ($targetCore > $maxCore) {
            $targetCore = $maxCore;
        }

        $classified = [];
        $assigned   = 0;

        foreach ($scores as $dayKey => $score) {
            $category = $assigned < $targetCore ? 'core' : 'peripheral';
            ++$assigned;

            $classified[$dayKey] = [
                'score'    => $metadata[$dayKey]['score'],
                'category' => $category,
                'duration' => $metadata[$dayKey]['duration'],
                'metrics'  => $metadata[$dayKey]['metrics'],
            ];
        }

        return $classified;
    }

    /**
     * @param array{members:list<Media>,tourismRatio:float,poiDensity:float,staypointCount:int,spotCount:int,cohortPresenceRatio:float,isSynthetic:bool} $summary
     */
    private function calculateCoreScore(array $summary): float
    {
        $members = $summary['members'];
        $memberCount = count($members);

        $faceCount = 0;
        $qualitySamples = [];
        foreach ($members as $media) {
            if ($media->hasFaces()) {
                ++$faceCount;
            }

            $quality = $media->getQualityScore();
            if ($quality !== null) {
                $qualitySamples[] = $quality;
            }
        }

        $faceShare = $memberCount > 0 ? $faceCount / $memberCount : 0.0;
        if ($faceShare === 0.0) {
            $faceShare = (float) $summary['cohortPresenceRatio'];
        }

        $qualityMedian = $this->median($qualitySamples);
        if ($qualityMedian === null) {
            $qualityMedian = 0.5;
        }

        $diversityBase = max(0, ($summary['staypointCount'] ?? 0) + ($summary['spotCount'] ?? 0));
        $diversity     = min(1.0, $diversityBase / 6.0);

        $poiBoost = $summary['tourismRatio'];
        if ($summary['poiDensity'] > 0.0) {
            $poiBoost += min(0.25, $summary['poiDensity'] * 0.5);
        }

        $poiBoost = max(0.0, min(1.0, $poiBoost));

        $weights = [
            $diversity * 0.3,
            $faceShare * 0.25,
            $poiBoost * 0.2,
            $qualityMedian * 0.25,
        ];

        $score = 0.0;
        foreach ($weights as $component) {
            $score += $component;
        }

        if ($summary['isSynthetic']) {
            $score *= 0.6;
        }

        return round(max(0.0, min(1.0, $score)), 3);
    }

    /**
     * @param list<Media> $members
     */
    private function calculateDayDuration(array $members): ?int
    {
        if ($members === []) {
            return null;
        }

        $timestamps = [];
        foreach ($members as $media) {
            $takenAt = $media->getTakenAt();
            if ($takenAt === null) {
                continue;
            }

            $timestamps[] = $takenAt->getTimestamp();
        }

        if ($timestamps === []) {
            return null;
        }

        sort($timestamps, SORT_NUMERIC);

        return (int) max(0, end($timestamps) - $timestamps[0]);
    }

    /**
     * @param list<float> $samples
     */
    private function median(array $samples): ?float
    {
        $count = count($samples);
        if ($count === 0) {
            return null;
        }

        sort($samples, SORT_NUMERIC);

        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $samples[$middle];
        }

        return ($samples[$middle - 1] + $samples[$middle]) / 2.0;
    }

    /**
     * @param array{tourismRatio:float,poiDensity:float,cohortPresenceRatio:float} $summary
     *
     * @return array<string, float>
     */
    private function buildCoreMetrics(array $summary, float $score): array
    {
        return [
            'score'                 => $score,
            'tourism_ratio'         => (float) $summary['tourismRatio'],
            'poi_density'           => (float) $summary['poiDensity'],
            'cohort_presence_ratio' => (float) $summary['cohortPresenceRatio'],
        ];
    }
}
