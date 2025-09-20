<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service;

use Throwable;
use function is_array;
use function sprintf;

class LocationService implements LocationServiceInterface
{
    private string $endpoint = 'https://nominatim.openstreetmap.org/reverse';

    /**
     * Liefert einen Ortstitel (z. B. "Rom, Italien") für Koordinaten.
     */
    public function reverseGeocode(float $lat, float $lon): ?string
    {
        $url = sprintf(
            '%s?lat=%F&lon=%F&format=jsonv2',
            $this->endpoint,
            $lat,
            $lon
        );

        $opts = [
            'http' => [
                'method'  => 'GET',
                'header'  => [
                    'User-Agent: photo-memories-cli/1.0 (your-email@example.com)',
                ],
                'timeout' => 5,
            ],
        ];

        try {
            $context  = stream_context_create($opts);
            $response = file_get_contents($url, false, $context);

            if ($response === false) {
                return null;
            }

            $data = json_decode(
                $response,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($data) || empty($data['address'])) {
                return null;
            }

            $address = $data['address'];
            $city    = $address['city'] ?? $address['town'] ?? $address['village'] ?? null;
            $country = $address['country'] ?? null;

            if ($city && $country) {
                return $city . ', ' . $country;
            }

            if ($country) {
                return $country;
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }
}
