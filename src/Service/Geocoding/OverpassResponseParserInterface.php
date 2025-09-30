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
 * Parses responses returned by the Overpass API.
 */
interface OverpassResponseParserInterface
{
    /**
     * @param array<string,mixed> $payload
     *
     * @return list<array<string,mixed>>
     */
    public function parse(array $payload, float $lat, float $lon, ?int $limit): array;
}
