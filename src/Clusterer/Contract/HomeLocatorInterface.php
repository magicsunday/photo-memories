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
 * Defines how a home location for vacation clustering is determined.
 */
interface HomeLocatorInterface
{
    /**
     * @param list<Media> $items
     *
     * @return array{
     *     lat: float,
     *     lon: float,
     *     radius_km: float,
     *     country: string|null,
     *     timezone_offset: int|null
     * }|null
     */
    public function determineHome(array $items): ?array;

    /**
     * Returns the statically configured home reference when available.
     *
     * @return array{lat: float, lon: float, radius_km: float}|null
     */
    public function getConfiguredHome(): ?array;
}
