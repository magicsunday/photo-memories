<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service;

use RuntimeException;

use function sprintf;

class GeocodingService
{
    private readonly LocationService $cache;

    public function __construct(LocationService $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Reverse geocode coordinates to a place name.
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     *
     * @return array{display_name: string, city?: string, country?: string}|null
     */
    public function reverseGeocode(float $lat, float $lon): ?array
    {
        $query = sprintf(
            'reverse:%f,%f',
            $lat,
            $lon
        );

        // 1. Try cache
        $cached = $this->cache->getCachedResult($query);
        if ($cached !== null) {
            return json_decode(
                $cached,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        // 2. Call Nominatim API
        $url = sprintf(
            'https://nominatim.openstreetmap.org/reverse?lat=%f&lon=%f&format=jsonv2&addressdetails=1',
            $lat,
            $lon
        );

        $opts = [
            'http' => [
                'header' => [
                    'User-Agent: Memories-App/1.0 (contact: you@example.com)',
                ],
                'timeout' => 10,
            ],
        ];

        $ctx  = stream_context_create($opts);
        $json = @file_get_contents(
            $url,
            false,
            $ctx
        );

        if ($json === false) {
            throw new RuntimeException('Failed to fetch from Nominatim API: ' . $url);
        }

        $data = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        // 3. Store in cache
        $this->cache->cacheResult(
            $query,
            $json
        );

        // Extract useful info
        return [
            'display_name' => $data['display_name'] ?? '',
            'city'         => $data['address']['city'] ?? $data['address']['town'] ?? $data['address']['village'] ?? null,
            'country'      => $data['address']['country'] ?? null,
        ];
    }

    /**
     * Forward geocode place name to coordinates.
     *
     * @param string $place
     *
     * @return array{lat: float, lon: float, display_name: string}|null
     */
    public function forwardGeocode(string $place): ?array
    {
        $query = sprintf(
            'forward:%s',
            strtolower(trim($place))
        );

        // 1. Try cache
        $cached = $this->cache->getCachedResult($query);
        if ($cached !== null) {
            return json_decode(
                $cached,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        // 2. Call Nominatim API
        $url = sprintf(
            'https://nominatim.openstreetmap.org/search?q=%s&format=jsonv2&limit=1',
            urlencode($place)
        );

        $opts = [
            'http' => [
                'header' => [
                    'User-Agent: Memories-App/1.0 (contact: you@example.com)',
                ],
                'timeout' => 10,
            ],
        ];

        $ctx  = stream_context_create($opts);
        $json = @file_get_contents(
            $url,
            false,
            $ctx
        );

        if ($json === false) {
            throw new RuntimeException('Failed to fetch from Nominatim API: ' . $url);
        }

        $data = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if ($data === [] || !isset($data[0])) {
            return null;
        }

        $result = [
            'lat'          => (float) $data[0]['lat'],
            'lon'          => (float) $data[0]['lon'],
            'display_name' => $data[0]['display_name'] ?? $place,
        ];

        // 3. Store in cache
        $this->cache->cacheResult(
            $query,
            json_encode(
                $result,
                JSON_THROW_ON_ERROR
            )
        );

        return $result;
    }
}
