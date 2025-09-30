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
 * Resolves the primary category tag for a POI element.
 */
interface OverpassPrimaryTagResolverInterface
{
    /**
     * Determines the primary tag key/value pair from the provided tag list.
     *
     * @param array<string, mixed> $tags
     *
     * @return array{key: string, value: string}|null
     */
    public function resolve(array $tags): ?array;
}
