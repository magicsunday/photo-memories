<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

/**
 * Builds Overpass API queries.
 */
interface OverpassQueryBuilderInterface
{
    public function build(float $lat, float $lon, int $radius, ?int $limit): string;
}
