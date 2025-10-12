<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Contract;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;

/**
 * Calculates the vacation cluster score and metadata for a run of days.
 */
interface VacationScoreCalculatorInterface
{
    /**
     * @param list<string> $dayKeys
     * @param array<string, array{date:string,members:list<Media>,gpsMembers:list<Media>,maxDistanceKm:float,avgDistanceKm:float,travelKm:float,maxSpeedKmh:float,avgSpeedKmh:float,hasHighSpeedTransit:bool,countryCodes:array<string,true>,timezoneOffsets:array<int,int>,localTimezoneIdentifier:string,localTimezoneOffset:int|null,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,isAwayCandidate:bool,sufficientSamples:bool,spotClusters:list<list<Media>>,spotNoise:list<Media>,spotCount:int,spotNoiseSamples:int,spotDwellSeconds:int,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,staypointIndex:\MagicSunday\Memories\Clusterer\Support\StaypointIndex,staypointCounts:array<string,int>,dominantStaypoints:list<array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int>>,transitRatio:float,poiDensity:float,baseAway:bool,baseLocation:array{lat:float,lon:float,distance_km:float,source:string}|null,isSynthetic:bool}> $days
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
     * @param array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}> $dayContext
     */
    public function buildDraft(array $dayKeys, array $days, array $home, array $dayContext = []): ?ClusterDraft;
}
