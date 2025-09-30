<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Utility\MediaMath;

use function array_filter;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function is_array;
use function is_numeric;
use function is_string;
use function ksort;
use function round;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;
use function usort;

use const SORT_STRING;

final class DefaultOverpassResponseParser implements OverpassResponseParserInterface
{
    /**
     * Additional tags that are kept in the output even if they are not primary category keys.
     *
     * @var list<string>
     */
    private const array AUXILIARY_TAG_KEYS = [
        'wikidata',
    ];

    public function __construct(private readonly OverpassTagConfiguration $configuration)
    {
    }

    public function parse(array $payload, float $lat, float $lon, ?int $limit): array
    {
        $elements = $payload['elements'] ?? null;
        if (!is_array($elements)) {
            return [];
        }

        /** @var array<string,array<string,mixed>> $pois */
        $pois = [];
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $id = $this->elementId($element);
            if ($id === null) {
                continue;
            }

            if (isset($pois[$id])) {
                continue;
            }

            $coordinate = $this->extractCoordinate($element);
            if ($coordinate === null) {
                continue;
            }

            $tags = $element['tags'] ?? null;
            if (!is_array($tags)) {
                $tags = [];
            }

            $selection    = $this->selectRelevantTags($tags);
            $selectedTags = $selection['tags'];
            if ($selectedTags === []) {
                continue;
            }

            $names = $selection['names'];
            $name  = $this->fallbackPoiName($names);

            $primaryKey   = $this->primaryTagKey($tags);
            $primaryValue = $primaryKey !== null ? $this->stringOrNull($selectedTags[$primaryKey] ?? null) : null;

            if ($primaryKey === null || $primaryValue === null) {
                continue;
            }

            if ($name === null && $primaryValue === null) {
                continue;
            }

            $pois[$id] = [
                'id'             => $id,
                'name'           => $name,
                'names'          => $names,
                'categoryKey'    => $primaryKey,
                'categoryValue'  => $primaryValue,
                'lat'            => $coordinate['lat'],
                'lon'            => $coordinate['lon'],
                'distanceMeters' => round(
                    MediaMath::haversineDistanceInMeters($lat, $lon, $coordinate['lat'], $coordinate['lon']),
                    2
                ),
                'tags' => $selectedTags,
            ];
        }

        if ($pois === []) {
            return [];
        }

        $values = array_values($pois);
        usort(
            $values,
            static fn (array $a, array $b): int => $a['distanceMeters'] <=> $b['distanceMeters']
        );

        if ($limit !== null && count($values) > $limit) {
            return array_slice($values, 0, $limit);
        }

        return $values;
    }

    private function elementId(array $element): ?string
    {
        $type = $this->stringOrNull($element['type'] ?? null);
        $id   = $element['id'] ?? null;

        if ($type === null || (!is_numeric($id) && !is_string($id))) {
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

    private function primaryTagKey(array $tags): ?string
    {
        foreach ($this->configuration->getAllowedTagMap() as $key => $values) {
            $value = $this->stringOrNull($tags[$key] ?? null);
            if ($value === null) {
                continue;
            }

            if ($this->isAllowedTagValue($value, $values)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     tags: array<string,string>,
     *     names: array{
     *         default: ?string,
     *         localized: array<string,string>,
     *         alternates: list<string>
     *     }
     * }
     */
    private function selectRelevantTags(array $tags): array
    {
        $selected = $this->filterAllowedTags($tags);

        foreach (self::AUXILIARY_TAG_KEYS as $key) {
            $value = $this->stringOrNull($tags[$key] ?? null);
            if ($value !== null) {
                $selected[$key] = $value;
            }
        }

        $names = $this->extractNames($tags);

        return [
            'tags'  => $selected,
            'names' => $names,
        ];
    }

    /**
     * @return array{
     *     default: ?string,
     *     localized: array<string,string>,
     *     alternates: list<string>
     * }
     */
    private function extractNames(array $tags): array
    {
        $default = $this->stringOrNull($tags['name'] ?? null);

        /** @var array<string,string> $localized */
        $localized = [];
        foreach ($tags as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!str_starts_with($key, 'name:')) {
                continue;
            }

            $locale = substr($key, 5);
            if ($locale === false) {
                continue;
            }

            $locale = strtolower($locale);
            if ($locale === '') {
                continue;
            }

            $normalizedLocale = str_replace(' ', '_', $locale);
            $name             = $this->stringOrNull($value);
            if ($name === null) {
                continue;
            }

            $localized[$normalizedLocale] = $name;
        }

        if ($localized !== []) {
            ksort($localized, SORT_STRING);
        }

        $alternates = [];
        $altName    = $this->stringOrNull($tags['alt_name'] ?? null);
        if ($altName !== null) {
            $parts = array_map(static fn (string $part): string => trim($part), explode(';', $altName));
            $parts = array_filter($parts, static fn (string $part): bool => $part !== '');
            if ($parts !== []) {
                /** @var list<string> $unique */
                $unique     = array_values(array_unique($parts));
                $alternates = $unique;
            }
        }

        return [
            'default'    => $default,
            'localized'  => $localized,
            'alternates' => $alternates,
        ];
    }

    /**
     * @param array{
     *     default: ?string,
     *     localized: array<string,string>,
     *     alternates: list<string>
     * } $names
     */
    private function fallbackPoiName(array $names): ?string
    {
        $default = $names['default'];
        if ($default !== null) {
            return $default;
        }

        foreach ($names['localized'] as $name) {
            if ($name !== '') {
                return $name;
            }
        }

        foreach ($names['alternates'] as $alternate) {
            if ($alternate !== '') {
                return $alternate;
            }
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private function filterAllowedTags(array $tags): array
    {
        $allowed = [];
        foreach ($this->configuration->getAllowedTagMap() as $key => $values) {
            $value = $this->stringOrNull($tags[$key] ?? null);
            if ($value === null) {
                continue;
            }

            if ($this->isAllowedTagValue($value, $values)) {
                $allowed[$key] = $value;
            }
        }

        return $allowed;
    }

    /**
     * @param list<string> $allowedValues
     */
    private function isAllowedTagValue(string $value, array $allowedValues): bool
    {
        if ($allowedValues === []) {
            return false;
        }

        foreach ($allowedValues as $allowed) {
            if ($allowed === $value) {
                return true;
            }
        }

        return false;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
