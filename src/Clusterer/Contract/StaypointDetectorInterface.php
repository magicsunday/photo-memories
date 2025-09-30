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
 * Defines the staypoint detection contract for day summary clustering.
 */
interface StaypointDetectorInterface
{
    /**
     * Detects all staypoints from the given GPS-enabled media entries.
     *
     * @param list<Media> $gpsMembers
     *
     * @return list<array{lat:float,lon:float,start:int,end:int,dwell:int}>
     */
    public function detect(array $gpsMembers): array;
}
