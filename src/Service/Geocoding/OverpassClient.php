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
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_filter;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function ksort;
use function max;
use function number_format;
use function preg_quote;
use function round;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;
use function usort;

use const SORT_STRING;

/**
 * Minimal Overpass API client fetching nearby Points of Interest.
 */
final class OverpassClient
{
    /**
     * Default Overpass tag filters we consider for POI categorisation.
     *
     * @var array<string,list<string>>
     */
    private const array DEFAULT_ALLOWED_TAGS = [
        'tourism'  => ['attraction', 'viewpoint', 'museum', 'gallery'],
        'historic' => ['monument', 'castle', 'memorial'],
        'man_made' => ['tower', 'lighthouse'],
        'leisure'  => ['park', 'garden'],
        'natural'  => ['peak', 'cliff'],
    ];

    /**
     * Additional tags we keep even though they are not primary category keys.
     *
     * @var list<string>
     */
    private const array AUXILIARY_TAG_KEYS = [
        'wikidata',
    ];

    private bool $lastUsedNetwork = false;

    /**
     * @var array<string,list<string>>
     */
    private array $allowedTagMap;

    /**
     * @param float $httpTimeout timeout in seconds for the HTTP request (symfony client option)
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl = 'https://overpass-api.de/api',
        private readonly string $userAgent = 'Rueckblick/1.0',
        private readonly ?string $contactEmail = null,
        private readonly int $queryTimeout = 25,
        private readonly float $httpTimeout = 25.0,
        array $additionalAllowedTags = [],
    ) {
        $this->allowedTagMap = $this->mergeAllowedTags($additionalAllowedTags);
    }

    /**
     * Fetches POIs around given coordinates.
     *
     * @return list<array<string,mixed>>
     */
    public function fetchPois(float $lat, float $lon, int $radiusMeters, ?int $limit): array
    {
        $this->lastUsedNetwork = false;

        if ($radiusMeters <= 0) {
            return [];
        }

        $queryLimit = $limit !== null ? max(1, $limit) : null;
        $query      = $this->buildQuery($lat, $lon, $radiusMeters, $queryLimit);

        try {
            $this->lastUsedNetwork = true;
            $response              = $this->http->request('POST', $this->baseUrl . '/interpreter', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json',
                    'User-Agent'   => $this->userAgentWithContact(),
                ],
                'body' => [
                    'data' => $query,
                ],
                'timeout' => $this->httpTimeout,
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            /** @var array<string,mixed> $payload */
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|DecodingExceptionInterface) {
            return [];
        }

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
                continue; // skip noisier features without any textual context
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

        if ($queryLimit !== null && count($values) > $queryLimit) {
            return array_slice($values, 0, $queryLimit);
        }

        return $values;
    }

    public function consumeLastUsedNetwork(): bool
    {
        $used                  = $this->lastUsedNetwork;
        $this->lastUsedNetwork = false;

        return $used;
    }

    private function buildQuery(float $lat, float $lon, int $radius, ?int $limit): string
    {
        $latS   = number_format($lat, 7, '.', '');
        $lonS   = number_format($lon, 7, '.', '');
        $radius = max(1, $radius);

        $query = sprintf('[out:json][timeout:%d];(', $this->queryTimeout);
        foreach ($this->allowedTagMap as $key => $values) {
            if ($values === []) {
                continue;
            }

            $escaped = array_map(static fn (string $value): string => preg_quote($value, '/'), $values);
            $pattern = implode('|', $escaped);
            $query  .= sprintf('nwr(around:%d,%s,%s)["%s"~"^(%s)$"];', $radius, $latS, $lonS, $key, $pattern);
        }

        $limitFragment = $limit !== null ? ' ' . max(1, $limit) : '';

        return $query . sprintf(');out tags center%s;', $limitFragment);
    }

    private function elementId(array $element): ?string
    {
        $type = $this->stringOrNull($element['type'] ?? null);
        $id   = $element['id'] ?? null;

        if ($type === null || (!is_int($id) && !is_string($id))) {
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
        foreach ($this->allowedTagMap as $key => $values) {
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
        foreach ($this->allowedTagMap as $key => $values) {
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

        return in_array($value, $allowedValues, true);
    }

    /**
     * @param array<string,mixed> $additional
     *
     * @return array<string,list<string>>
     */
    private function mergeAllowedTags(array $additional): array
    {
        $merged = self::DEFAULT_ALLOWED_TAGS;

        foreach ($additional as $key => $values) {
            if (!is_string($key)) {
                continue;
            }

            if (is_string($values)) {
                $values = [$values];
            }

            if (!is_array($values)) {
                continue;
            }

            $normalized = [];
            foreach ($values as $value) {
                $value = $this->stringOrNull($value);
                if ($value === null) {
                    continue;
                }

                $normalized[] = $value;
            }

            if ($normalized === []) {
                continue;
            }

            if (isset($merged[$key])) {
                $merged[$key] = array_values(array_unique(array_merge($merged[$key], $normalized)));
                continue;
            }

            $merged[$key] = array_values(array_unique($normalized));
        }

        return $merged;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function userAgentWithContact(): string
    {
        $email = $this->contactEmail;
        if ($email === null) {
            return $this->userAgent;
        }

        $trimmed = trim($email);
        if ($trimmed === '') {
            return $this->userAgent;
        }

        return $this->userAgent . ' (' . $trimmed . ')';
    }
}
