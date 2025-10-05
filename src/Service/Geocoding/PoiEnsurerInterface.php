<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Entity\Location;

/**
 * Interface PoiEnsurerInterface
 */
interface PoiEnsurerInterface
{
    public function ensurePois(Location $location, bool $refreshPois = false): void;

    public function consumeLastUsedNetwork(): bool;
}
