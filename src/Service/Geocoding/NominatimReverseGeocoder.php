<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reverse geocoding via Nominatim (OpenStreetMap).
 * Be polite: identify User-Agent and respect rate limits in caller.
 */
final class NominatimReverseGeocoder implements ReverseGeocoderInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl = 'https://nominatim.openstreetmap.org',
        private readonly string $userAgent = 'Rueckblick/1.0',
        private readonly ?string $contactEmail = null,
        private readonly int $zoom = 14 // 0..18 – 14 = street level
    ) {
    }

    public function reverse(float $lat, float $lon, string $acceptLanguage = 'de'): ?GeocodeResult
    {
        $headers = [
            'User-Agent'      => $this->contactEmail !== null ? $this->userAgent.' ('.$this->contactEmail.')' : $this->userAgent,
            'Accept'          => 'application/json',
            'Accept-Language' => $acceptLanguage,
        ];

        $resp = $this->http->request('GET', $this->baseUrl.'/reverse', [
            'headers' => $headers,
            'query'   => [
                'format'         => 'jsonv2',
                'lat'            => \number_format($lat, 8, '.', ''),
                'lon'            => \number_format($lon, 8, '.', ''),
                'zoom'           => (string) $this->zoom,
                'addressdetails' => '1',
                'namedetails'    => '0',
            ],
            'timeout' => 10.0,
        ]);

        if ($resp->getStatusCode() !== 200) {
            return null;
        }

        /** @var array<string,mixed> $data */
        $data = (array) $resp->toArray(false);

        $placeId = isset($data['place_id']) ? (string) $data['place_id'] : null;
        $display = isset($data['display_name']) && \is_string($data['display_name']) ? $data['display_name'] : null;
        $latS = isset($data['lat']) ? (string) $data['lat'] : null;
        $lonS = isset($data['lon']) ? (string) $data['lon'] : null;

        if ($placeId === null || $display === null || $latS === null || $lonS === null) {
            return null;
        }

        $addr = (array) ($data['address'] ?? []);
        /** @var list<float>|null $bbox */
        $bbox = null;
        if (\is_array($data['boundingbox'] ?? null)) {
            $b = $data['boundingbox'];
            if (\count($b) === 4) {
                $bbox = [ (float) $b[0], (float) $b[1], (float) $b[2], (float) $b[3] ];
            }
        }

        return new GeocodeResult(
            provider: 'nominatim',
            providerPlaceId: $placeId,
            lat: (float) $latS,
            lon: (float) $lonS,
            displayName: $display,
            countryCode: isset($addr['country_code']) ? \strtoupper((string)$addr['country_code']) : null,
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
            type: $this->s($data['type'] ?? null)
        );
    }

    private function s(mixed $v): ?string
    {
        return \is_string($v) && $v !== '' ? $v : null;
    }
}
