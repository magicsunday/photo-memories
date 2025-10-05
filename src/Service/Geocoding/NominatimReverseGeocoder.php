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
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function number_format;
use function strtoupper;

/**
 * Reverse geocoding via Nominatim (OpenStreetMap).
 * Be polite: identify User-Agent and respect rate limits in caller.
 */
final readonly class NominatimReverseGeocoder implements ReverseGeocoderInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl = 'https://nominatim.openstreetmap.org',
        private string $userAgent = 'Rueckblick/1.0',
        private ?string $contactEmail = null,
        private int $zoom = 14, // 0..18 – 14 = street level
    ) {
    }

    public function reverse(float $lat, float $lon, string $acceptLanguage = 'de'): ?GeocodeResult
    {
        $headers = [
            'User-Agent'      => $this->contactEmail !== null ? $this->userAgent . ' (' . $this->contactEmail . ')' : $this->userAgent,
            'Accept'          => 'application/json',
            'Accept-Language' => $acceptLanguage,
        ];

        $resp = $this->http->request('GET', $this->baseUrl . '/reverse', [
            'headers' => $headers,
            'query'   => [
                'format'         => 'jsonv2',
                'lat'            => number_format($lat, 8, '.', ''),
                'lon'            => number_format($lon, 8, '.', ''),
                'zoom'           => (string) $this->zoom,
                'addressdetails' => '1',
                'namedetails'    => '1',
                'extratags'      => '1',
            ],
            'timeout' => 10.0,
        ]);

        if ($resp->getStatusCode() !== 200) {
            return null;
        }

        /** @var array<string,mixed> $data */
        $data = $resp->toArray(false);

        $placeId = isset($data['place_id']) ? (string) $data['place_id'] : null;
        $display = isset($data['display_name']) && is_string($data['display_name']) ? $data['display_name'] : null;
        $latS    = isset($data['lat']) ? (string) $data['lat'] : null;
        $lonS    = isset($data['lon']) ? (string) $data['lon'] : null;

        if ($placeId === null || $display === null || $latS === null || $lonS === null) {
            return null;
        }

        $addr = (array) ($data['address'] ?? []);
        /** @var list<float>|null $bbox */
        $bbox = null;
        if (is_array($data['boundingbox'] ?? null)) {
            $b = $data['boundingbox'];
            if (count($b) === 4) {
                $bbox = [(float) $b[0], (float) $b[1], (float) $b[2], (float) $b[3]];
            }
        }

        $extraTags = $this->stringMap($data['extratags'] ?? null);
        $altNames  = $this->stringMap($data['namedetails'] ?? null);

        $attribution = $this->s($data['attribution'] ?? ($data['powered_by'] ?? null));
        $licence     = $this->s($data['licence'] ?? null);
        $confidence  = isset($data['importance']) ? (float) $data['importance'] : null;
        $osmType     = $this->s($data['osm_type'] ?? null);
        $osmId       = isset($data['osm_id']) ? (string) $data['osm_id'] : null;
        $timezone    = $this->s($data['timezone'] ?? ($extraTags['timezone'] ?? null));
        $wikidataId  = $this->s($extraTags['wikidata'] ?? ($data['wikidata'] ?? null));
        $wikipedia   = $this->s($extraTags['wikipedia'] ?? ($data['wikipedia'] ?? null));

        $accuracy = $this->accuracyRadius($bbox, (float) $latS, (float) $lonS);

        $refreshedAt = new DateTimeImmutable();

        return new GeocodeResult(
            provider: 'nominatim',
            providerPlaceId: $placeId,
            lat: (float) $latS,
            lon: (float) $lonS,
            displayName: $display,
            countryCode: isset($addr['country_code']) ? strtoupper((string) $addr['country_code']) : null,
            country: $this->s($addr['country'] ?? null),
            state: $this->s($addr['state'] ?? null),
            county: $this->s($addr['county'] ?? null),
            city: $this->s($addr['city'] ?? null),
            town: $this->s($addr['town'] ?? null),
            village: $this->s($addr['village'] ?? null),
            suburb: $this->s($addr['suburb'] ?? null),
            neighbourhood: $this->s($addr['neighbourhood'] ?? null),
            postcode: $this->s($addr['postcode'] ?? null),
            road: $this->s($addr['road'] ?? null),
            houseNumber: $this->s($addr['house_number'] ?? null),
            boundingBox: $bbox,
            category: $this->s($data['category'] ?? null),
            type: $this->s($data['type'] ?? null),
            attribution: $attribution,
            licence: $licence,
            refreshedAt: $refreshedAt,
            confidence: $confidence,
            accuracyRadiusMeters: $accuracy,
            timezone: $timezone,
            osmType: $osmType,
            osmId: $osmId,
            wikidataId: $wikidataId,
            wikipedia: $wikipedia,
            altNames: $altNames,
            extraTags: $extraTags,
        );
    }

    private function s(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @param list<float>|null $bbox
     */
    private function accuracyRadius(?array $bbox, float $lat, float $lon): ?float
    {
        if (!is_array($bbox) || count($bbox) !== 4) {
            return null;
        }

        [$south, $north, $west, $east] = $bbox;
        if (!is_numeric($south) || !is_numeric($north) || !is_numeric($west) || !is_numeric($east)) {
            return null;
        }

        $distances = [
            MediaMath::haversineDistanceInMeters($lat, $lon, (float) $north, $lon),
            MediaMath::haversineDistanceInMeters($lat, $lon, (float) $south, $lon),
            MediaMath::haversineDistanceInMeters($lat, $lon, $lat, (float) $east),
            MediaMath::haversineDistanceInMeters($lat, $lon, $lat, (float) $west),
        ];

        $radius = max($distances);
        if (!is_numeric($radius)) {
            return null;
        }

        $radiusFloat = (float) $radius;

        return $radiusFloat > 0.0 ? $radiusFloat : null;
    }

    /**
     * @return array<string,string>|null
     */
    private function stringMap(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $map = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && is_string($v) && $v !== '') {
                $map[$k] = $v;
            }
        }

        if ($map === []) {
            return null;
        }

        return $map;
    }
}
