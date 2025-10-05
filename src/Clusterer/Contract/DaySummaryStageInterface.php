<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Contract;

/**
 * @phpstan-type HomeDescriptor array{
 *     lat:float,
 *     lon:float,
 *     radius_km:float,
 *     country:string|null,
 *     timezone_offset:int|null,
 *     centers?:list<array{lat:float,lon:float,radius_km:float,member_count?:int,dwell_seconds?:int}>
 * }
 */

/**
 * Contract for incremental day summary pipeline stages.
 */
interface DaySummaryStageInterface
{
    /**
     * @param array<string, mixed>|list<mixed>                                                        $days
     * @param HomeDescriptor                                                                          $home
     *
     * @return array<string, mixed>
     */
    public function process(array $days, array $home): array;
}
