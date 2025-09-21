<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

/**
 * Immutable reverse-geocoding result mapped to our domain.
 */
final class GeocodeResult
{
    public function __construct(
        public readonly string $provider,            // e.g. "nominatim"
        public readonly string $providerPlaceId,     // e.g. Nominatim place_id (stringified)
        public readonly float $lat,
        public readonly float $lon,
        public readonly string $displayName,
        public readonly ?string $countryCode,
        public readonly ?string $country,
        public readonly ?string $state,
        public readonly ?string $county,
        public readonly ?string $city,
        public readonly ?string $town,
        public readonly ?string $village,
        public readonly ?string $suburb,
        public readonly ?string $neighbourhood,
        public readonly ?string $postcode,
        public readonly ?string $road,
        public readonly ?string $houseNumber,
        /** @var list<float>|null [south, north, west, east] */
        public readonly ?array $boundingBox,
        public readonly ?string $category,           // poi category/type if available
        public readonly ?string $type                // e.g. "residential", "house", "city"
    ) {
    }
}
