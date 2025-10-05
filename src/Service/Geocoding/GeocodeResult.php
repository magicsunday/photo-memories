<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use DateTimeImmutable;

/**
 * Immutable reverse-geocoding result mapped to our domain.
 */
final readonly class GeocodeResult
{
    /**
     * @param string                    $provider             provider identifier (for example, "nominatim")
     * @param string                    $providerPlaceId      provider specific place identifier
     * @param float                     $lat                  latitude in decimal degrees
     * @param float                     $lon                  longitude in decimal degrees
     * @param string                    $displayName          human readable label
     * @param string|null               $countryCode          ISO country code when available
     * @param string|null               $country              country name
     * @param string|null               $state                state or region name
     * @param string|null               $county               county or administrative district
     * @param string|null               $city                 city name
     * @param string|null               $town                 town name as provided by the geocoder
     * @param string|null               $village              village or hamlet name
     * @param string|null               $suburb               suburb designation
     * @param string|null               $neighbourhood        neighbourhood designation
     * @param string|null               $postcode             postal code
     * @param string|null               $road                 street or road name
     * @param string|null               $houseNumber          house number
     * @param list<float>|null          $boundingBox          bounding box [south, north, west, east]
     * @param string|null               $category             provider category
     * @param string|null               $type                 provider type (for example, "city")
     * @param string|null               $attribution          attribution required by the provider
     * @param string|null               $licence              licence string supplied by the provider
     * @param DateTimeImmutable|null    $refreshedAt          timestamp when the result was retrieved
     * @param float|null                $confidence           provider supplied confidence/importance score
     * @param float|null                $accuracyRadiusMeters accuracy radius estimate in meters
     * @param string|null               $timezone             timezone identifier when known
     * @param string|null               $osmType              OSM element type (node/way/relation)
     * @param string|null               $osmId                OSM element identifier
     * @param string|null               $wikidataId           wikidata reference identifier
     * @param string|null               $wikipedia            wikipedia reference string
     * @param array<string,string>|null $altNames             alternative names keyed by language or qualifier
     * @param array<string,string>|null $extraTags            additional provider tags
     */
    public function __construct(
        public string $provider,
        public string $providerPlaceId,
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
        public ?array $boundingBox,
        public ?string $category,
        public ?string $type,
        public ?string $attribution,
        public ?string $licence,
        public ?DateTimeImmutable $refreshedAt,
        public ?float $confidence,
        public ?float $accuracyRadiusMeters,
        public ?string $timezone,
        public ?string $osmType,
        public ?string $osmId,
        public ?string $wikidataId,
        public ?string $wikipedia,
        public ?array $altNames,
        public ?array $extraTags,
    ) {
    }
}
