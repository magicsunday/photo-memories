<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Contract;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;

/**
 * Resolves timezones for media and day summaries.
 */
interface TimezoneResolverInterface
{
    /**
     * Determines the timezone for an individual media item.
     *
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
     */
    public function resolveMediaTimezone(Media $media, DateTimeImmutable $takenAt, array $home): DateTimeZone;

    /**
     * Resolves the timezone for a day summary.
     *
     * @param array{localTimezoneIdentifier:string,localTimezoneOffset:int|null,timezoneOffsets:array<int,int>} $summary
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null}           $home
     */
    public function resolveSummaryTimezone(array $summary, array $home): DateTimeZone;

    /**
     * Computes the preferred local timezone offset from vote counts.
     *
     * @param array<int,int>                                                                          $offsetVotes
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
     */
    public function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int;

    /**
     * Chooses the best timezone identifier for a day summary.
     *
     * @param array<string,int>                                                                       $identifierVotes
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
     */
    public function determineLocalTimezoneIdentifier(
        array $identifierVotes,
        array $home,
        ?int $offset,
    ): string;
}
