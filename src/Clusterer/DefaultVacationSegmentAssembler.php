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
     * @param array<string, array{date:string,members:list<\MagicSunday\Memories\Entity\Media>,gpsMembers:list<\MagicSunday\Memories\Entity\Media>,maxDistanceKm:float,avgDistanceKm:float,travelKm:float,countryCodes:array<string,true>,timezoneOffsets:array<int,int>,localTimezoneIdentifier:string,localTimezoneOffset:int|null,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,isAwayCandidate:bool,sufficientSamples:bool,spotClusters:list<list<\MagicSunday\Memories\Entity\Media>>,spotNoise:list<\MagicSunday\Memories\Entity\Media>,spotCount:int,spotNoiseSamples:int,spotDwellSeconds:int,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,baseLocation:array{lat:float,lon:float,distance_km:float,source:string}|null,baseAway:bool,awayByDistance:bool,firstGpsMedia:\MagicSunday\Memories\Entity\Media|null,lastGpsMedia:\MagicSunday\Memories\Entity\Media|null,isSynthetic:bool}> $days
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     *
     * @return list<ClusterDraft>
     */
    public function detectSegments(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        $clusters = [];
        $runs = $this->runDetector->detectVacationRuns($days, $home);
        foreach ($runs as $run) {
            $draft = $this->scoreCalculator->buildDraft($run, $days, $home);
            if ($draft instanceof ClusterDraft) {
                $clusters[] = $draft;
            }
        }

        return $clusters;
    }
}
