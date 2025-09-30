<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Contract;

use MagicSunday\Memories\Entity\Location;

/**
 * Provides heuristics for point-of-interest classification.
 */
interface PoiClassifierInterface
{
    public function isPoiSample(Location $location): bool;

    public function isTourismPoi(Location $location): bool;

    public function isTransportPoi(Location $location): bool;
}
