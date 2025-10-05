<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Contract;

use DateTimeZone;
use MagicSunday\Memories\Entity\Media;

/**
 * Resolves the daily base location for vacation day summaries.
 */
interface BaseLocationResolverInterface
{
    /**
     * Determines the most plausible base location for the provided day summary.
     *
     * @param array{date:string,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,firstGpsMedia:Media|null,lastGpsMedia:Media|null,gpsMembers:list<Media>} $summary
     * @param array{date:string,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,firstGpsMedia:Media|null}|null $nextSummary
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
     *
     * @return array{lat:float,lon:float,distance_km:float,source:string}|null
     */
    public function resolve(
        array $summary,
        ?array $nextSummary,
        array $home,
        DateTimeZone $timezone,
    ): ?array;
}
