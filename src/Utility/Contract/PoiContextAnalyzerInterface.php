<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility\Contract;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;

/**
 * Analyses points of interest associated with media locations.
 */
interface PoiContextAnalyzerInterface
{
    /**
     * Returns the most relevant POI for a location after applying the internal ranking heuristics.
     *
     * @return array{
     *     name:string|null,
     *     names:array{default:string|null,localized:array<string,string>,alternates:list<string>},
     *     categoryKey:string|null,
     *     categoryValue:string|null,
     *     tags:array<string,string>
     * }|null
     */
    public function resolvePrimaryPoi(Location $location): ?array;

    public function bestLabelForLocation(Location $location): ?string;

    /**
     * @param list<Media> $members
     *
     * @return array{label:string,categoryKey:string|null,categoryValue:string|null,tags:array<string,string>}|null
     */
    public function majorityPoiContext(array $members): ?array;
}
