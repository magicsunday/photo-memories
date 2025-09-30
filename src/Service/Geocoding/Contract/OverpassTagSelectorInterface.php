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
 * Selects the relevant tags and names from Overpass POI metadata.
 */
interface OverpassTagSelectorInterface
{
    /**
     * Reduces raw tags to the allowed subset and collects all supported name variants.
     *
     * @param array<string, mixed> $tags
     *
     * @return array{
     *     tags: array<string, string>,
     *     names: array{
     *         default: ?string,
     *         localized: array<string, string>,
     *         alternates: list<string>
     *     }
     * }
     */
    public function select(array $tags): array;
}
