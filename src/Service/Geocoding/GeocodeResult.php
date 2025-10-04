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
     * @param string                     $provider            Provider identifier (for example, "nominatim").
     * @param string                     $providerPlaceId     Provider specific place identifier.
     * @param float                      $lat                 Latitude in decimal degrees.
     * @param float                      $lon                 Longitude in decimal degrees.
     * @param string                     $displayName         Human readable label.
     * @param string|null                $countryCode         ISO country code when available.
     * @param string|null                $country             Country name.
     * @param string|null                $state               State or region name.
     * @param string|null                $county              County or administrative district.
     * @param string|null                $city                City name.
     * @param string|null                $town                Town name as provided by the geocoder.
     * @param string|null                $village             Village or hamlet name.
     * @param string|null                $suburb              Suburb designation.
     * @param string|null                $neighbourhood       Neighbourhood designation.
     * @param string|null                $postcode            Postal code.
     * @param string|null                $road                Street or road name.
     * @param string|null                $houseNumber         House number.
     * @param list<float>|null           $boundingBox         Bounding box [south, north, west, east].
     * @param string|null                $category            Provider category.
     * @param string|null                $type                Provider type (for example, "city").
     * @param string|null                $attribution         Attribution required by the provider.
     * @param string|null                $licence             Licence string supplied by the provider.
     * @param DateTimeImmutable|null     $refreshedAt         Timestamp when the result was retrieved.
     * @param float|null                 $confidence          Provider supplied confidence/importance score.
     * @param float|null                 $accuracyRadiusMeters Accuracy radius estimate in meters.
     * @param string|null                $timezone            Timezone identifier when known.
     * @param string|null                $osmType             OSM element type (node/way/relation).
     * @param string|null                $osmId               OSM element identifier.
     * @param string|null                $wikidataId          Wikidata reference identifier.
     * @param string|null                $wikipedia           Wikipedia reference string.
     * @param array<string,string>|null  $altNames            Alternative names keyed by language or qualifier.
     * @param array<string,string>|null  $extraTags           Additional provider tags.
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
