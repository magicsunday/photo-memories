<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

interface ReverseGeocoderInterface
{
    public function reverse(float $lat, float $lon, string $acceptLanguage = 'de'): ?GeocodeResult;
}
