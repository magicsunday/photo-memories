<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\Contract\OverpassElementFilterInterface;

use function is_array;
use function is_numeric;
use function is_string;

/**
 * Class OverpassElementFilter.
 */
final class OverpassElementFilter implements OverpassElementFilterInterface
{
    /**
     * @param array<string, mixed> $element
     *
     * @return array{id: string, lat: float, lon: float, tags: array<string, mixed>}|null
     */
    public function filter(array $element): ?array
    {
        $id = $this->elementId($element);
        if ($id === null) {
            return null;
        }

        $coordinate = $this->extractCoordinate($element);
        if ($coordinate === null) {
            return null;
        }

        $tags = $element['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }

        return [
            'id'   => $id,
            'lat'  => $coordinate['lat'],
            'lon'  => $coordinate['lon'],
            'tags' => $tags,
        ];
    }

    /**
     * @param array<string, mixed> $element
     */
    private function elementId(array $element): ?string
    {
        $type = $element['type'] ?? null;
        $id   = $element['id'] ?? null;

        if (!is_string($type) || ($type === '')) {
            return null;
        }

        if (!is_numeric($id) && !is_string($id)) {
            return null;
        }

        return $type . '/' . $id;
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    private function extractCoordinate(array $element): ?array
    {
        $lat = $element['lat'] ?? null;
        $lon = $element['lon'] ?? null;

        if (is_numeric($lat) && is_numeric($lon)) {
            return ['lat' => (float) $lat, 'lon' => (float) $lon];
        }

        $center = $element['center'] ?? null;
        if (is_array($center) && is_numeric($center['lat'] ?? null) && is_numeric($center['lon'] ?? null)) {
            return ['lat' => (float) $center['lat'], 'lon' => (float) $center['lon']];
        }

        return null;
    }
}
