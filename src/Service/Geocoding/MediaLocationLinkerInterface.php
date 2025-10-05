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
use MagicSunday\Memories\Entity\Media;

/**
 * Interface MediaLocationLinkerInterface
 */
interface MediaLocationLinkerInterface
{
    public function link(Media $media, string $acceptLanguage = 'de', bool $forceRefreshPois = false): ?Location;

    public function consumeLastNetworkCalls(): int;
}
