<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding\Contract;

/**
 * Filters raw Overpass API elements to the subset usable for POI processing.
 */
interface OverpassElementFilterInterface
{
    /**
     * Validates a raw Overpass element and extracts the normalized structure used by the parser.
     *
     * @param array<string, mixed> $element
     *
     * @return array{
     *     id: string,
     *     lat: float,
     *     lon: float,
     *     tags: array<string, mixed>
     * }|null
     */
    public function filter(array $element): ?array;
}
