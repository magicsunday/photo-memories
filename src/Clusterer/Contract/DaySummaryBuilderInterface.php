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
 * Builds per-day statistics used to assemble vacation segments.
 */
interface DaySummaryBuilderInterface
{
    /**
     * @param list<Media>                                                   $items
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
     *
     * @return array<string, array{
     *     date: string,
     *     members: list<Media>,
     *     gpsMembers: list<Media>,
     *     maxDistanceKm: float,
     *     avgDistanceKm: float,
     *     travelKm: float,
     *     countryCodes: array<string, true>,
     *     timezoneOffsets: array<int, int>,
     *     localTimezoneIdentifier: string,
     *     localTimezoneOffset: int|null,
     *     tourismHits: int,
     *     poiSamples: int,
     *     tourismRatio: float,
     *     hasAirportPoi: bool,
     *     weekday: int,
     *     photoCount: int,
     *     densityZ: float,
     *     isAwayCandidate: bool,
     *     sufficientSamples: bool,
     *     spotClusters: list<list<Media>>,
     *     spotNoise: list<Media>,
     *     spotCount: int,
     *     spotNoiseSamples: int,
     *     spotDwellSeconds: int,
     *     staypoints: list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,
     *     baseLocation: array{lat:float,lon:float,distance_km:float,source:string}|null,
     *     baseAway: bool,
     *     awayByDistance: bool,
     *     firstGpsMedia: Media|null,
     *     lastGpsMedia: Media|null,
     *     isSynthetic: bool,
     * }>
     */
    public function buildDaySummaries(array $items, array $home): array;
}
