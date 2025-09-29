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
 * Immutable reverse-geocoding result mapped to our domain.
 */
final readonly class GeocodeResult
{
    public function __construct(
        public string $provider,            // e.g. "nominatim"
        public string $providerPlaceId,     // e.g. Nominatim place_id (stringified)
        public float $lat,
        public float $lon,
        public string $displayName,
        public ?string $countryCode,
        public ?string $country,
        public ?string $state,
        public ?string $county,
        public ?string $city,
        public ?string $town,
        public ?string $village,
        public ?string $suburb,
        public ?string $neighbourhood,
        public ?string $postcode,
        public ?string $road,
        public ?string $houseNumber,
        /** @var list<float>|null [south, north, west, east] */
        public ?array $boundingBox,
        public ?string $category,           // poi category/type if available
        public ?string $type,                // e.g. "residential", "house", "city"
    ) {
    }
}
