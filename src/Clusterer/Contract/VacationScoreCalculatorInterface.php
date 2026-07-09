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

/**
 * Calculates the vacation cluster score and metadata for a run of days.
 */
interface VacationScoreCalculatorInterface
{
    /**
     * @param list<string>                                                                                    $dayKeys
     * @param array<string, mixed>                                                                            $days
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null}         $home
     * @param array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}> $dayContext
     */
    public function buildDraft(array $dayKeys, array $days, array $home, array $dayContext = []): ?ClusterDraft;
}
