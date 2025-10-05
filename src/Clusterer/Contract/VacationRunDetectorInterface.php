<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Contract;

use MagicSunday\Memories\Entity\Media;

/**
 * Detects potential vacation runs using daily summaries.
 */
interface VacationRunDetectorInterface
{
    /**
     * @param array<string, array{date:string,members:list<Media>,gpsMembers:list<Media>,maxDistanceKm:float,avgDistanceKm:float,travelKm:float,countryCodes:array<string,true>,timezoneOffsets:array<int,int>,localTimezoneIdentifier:string,localTimezoneOffset:int|null,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,isAwayCandidate:bool,sufficientSamples:bool,spotClusters:list<list<Media>>,spotNoise:list<Media>,spotCount:int,spotNoiseSamples:int,spotDwellSeconds:int,baseLocation:array{lat:float,lon:float,distance_km:float,source:string}|null,baseAway:bool,awayByDistance:bool,firstGpsMedia:Media|null,lastGpsMedia:Media|null,isSynthetic:bool}> $days
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
     *
     * @return list<list<string>>
     */
    public function detectVacationRuns(array $days, array $home): array;
}
