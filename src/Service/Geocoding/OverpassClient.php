<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal Overpass API client fetching nearby Points of Interest.
 */
final class OverpassClient
{
    /**
     * Relevant tag keys we consider for POI categorisation.
     *
     * @var list<string>
     */
    private const TAG_KEYS = [
        'tourism',
        'amenity',
        'leisure',
        'sport',
        'historic',
        'man_made',
        'shop',
        'natural',
        'landuse',
        'place',
        'building',
    ];

    /**
     * Additional tags we keep even though they are not primary category keys.
     *
     * @var list<string>
     */
    private const AUXILIARY_TAG_KEYS = [
        'wikidata',
    ];

    private bool $lastUsedNetwork = false;

    /**
     * @param float $httpTimeout Timeout in seconds for the HTTP request (symfony client option).
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl = 'https://overpass-api.de/api',
        private readonly string $userAgent = 'Rueckblick/1.0',
        private readonly ?string $contactEmail = null,
        private readonly int $queryTimeout = 25,
        private readonly float $httpTimeout = 25.0
    ) {
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

        $queryLimit = $limit !== null ? \max(1, $limit) : null;
        $query = $this->buildQuery($lat, $lon, $radiusMeters, $queryLimit);

        try {
            $this->lastUsedNetwork = true;
            $response = $this->http->request('POST', $this->baseUrl.'/interpreter', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json',
                    'User-Agent'   => $this->userAgentWithContact(),
                ],
                'body'    => [
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
        if (!\is_array($elements)) {
            return [];
        }

        /** @var array<string,array<string,mixed>> $pois */
        $pois = [];
        foreach ($elements as $element) {
            if (!\is_array($element)) {
                continue;
            }

            $id = $this->elementId($element);
            if ($id === null || isset($pois[$id])) {
                continue;
            }

            $coordinate = $this->extractCoordinate($element);
            if ($coordinate === null) {
                continue;
            }

            $tags = $element['tags'] ?? null;
            if (!\is_array($tags)) {
                $tags = [];
            }

            $selection = $this->selectRelevantTags($tags);
            $selectedTags = $selection['tags'];
            $names = $selection['names'];
            $name = $this->fallbackPoiName($names);

            $primaryKey = $this->primaryTagKey($tags);
            $primaryValue = $primaryKey !== null ? $this->stringOrNull($tags[$primaryKey] ?? null) : null;

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
                'distanceMeters' => \round(
                    MediaMath::haversineDistanceInMeters($lat, $lon, $coordinate['lat'], $coordinate['lon']),
                    2
                ),
                'tags'           => $selectedTags,
            ];
        }

        if ($pois === []) {
            return [];
        }

        $values = \array_values($pois);
        \usort(
            $values,
            static fn (array $a, array $b): int => $a['distanceMeters'] <=> $b['distanceMeters']
        );

        if ($queryLimit !== null && \count($values) > $queryLimit) {
            $values = \array_slice($values, 0, $queryLimit);
        }

        return $values;
    }

    public function consumeLastUsedNetwork(): bool
    {
        $used = $this->lastUsedNetwork;
        $this->lastUsedNetwork = false;

        return $used;
    }

    private function buildQuery(float $lat, float $lon, int $radius, ?int $limit): string
    {
        $latS = \number_format($lat, 7, '.', '');
        $lonS = \number_format($lon, 7, '.', '');
        $radius = \max(1, $radius);

        $query = \sprintf('[out:json][timeout:%d];(', $this->queryTimeout);
        foreach (self::TAG_KEYS as $key) {
            $query .= \sprintf('nwr(around:%d,%s,%s)["%s"];', $radius, $latS, $lonS, $key);
        }
        $limitFragment = $limit !== null ? ' '.\max(1, $limit) : '';
        $query .= \sprintf(');out tags center%s;', $limitFragment);

        return $query;
    }

    private function elementId(array $element): ?string
    {
        $type = $this->stringOrNull($element['type'] ?? null);
        $id = $element['id'] ?? null;

        if ($type === null || (!\is_int($id) && !\is_string($id))) {
            return null;
        }

        return $type.'/'.(string) $id;
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    private function extractCoordinate(array $element): ?array
    {
        $lat = $element['lat'] ?? null;
        $lon = $element['lon'] ?? null;

        if (\is_numeric($lat) && \is_numeric($lon)) {
            return [ 'lat' => (float) $lat, 'lon' => (float) $lon ];
        }

        $center = $element['center'] ?? null;
        if (\is_array($center) && \is_numeric($center['lat'] ?? null) && \is_numeric($center['lon'] ?? null)) {
            return [ 'lat' => (float) $center['lat'], 'lon' => (float) $center['lon'] ];
        }

        return null;
    }

    private function primaryTagKey(array $tags): ?string
    {
        foreach ($this->relevantTagKeys() as $key) {
            if ($this->stringOrNull($tags[$key] ?? null) !== null) {
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
        $selected = [];
        foreach ($this->relevantTagKeys() as $key) {
            $value = $this->stringOrNull($tags[$key] ?? null);
            if ($value !== null) {
                $selected[$key] = $value;
            }
        }

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
            if (!\is_string($key) || !\str_starts_with($key, 'name:')) {
                continue;
            }

            $locale = \substr($key, 5);
            if ($locale === false) {
                continue;
            }

            $locale = \strtolower($locale);
            if ($locale === '') {
                continue;
            }

            $normalizedLocale = \str_replace(' ', '_', $locale);
            $name = $this->stringOrNull($value);
            if ($name === null) {
                continue;
            }

            $localized[$normalizedLocale] = $name;
        }

        if ($localized !== []) {
            \ksort($localized, \SORT_STRING);
        }

        $alternates = [];
        $altName = $this->stringOrNull($tags['alt_name'] ?? null);
        if ($altName !== null) {
            $parts = \array_map(static fn (string $part): string => \trim($part), \explode(';', $altName));
            $parts = \array_filter($parts, static fn (string $part): bool => $part !== '');
            if ($parts !== []) {
                /** @var list<string> $unique */
                $unique = \array_values(\array_unique($parts));
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
     * @return list<string>
     */
    private function relevantTagKeys(): array
    {
        return self::TAG_KEYS;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function userAgentWithContact(): string
    {
        $email = $this->contactEmail;
        if ($email === null) {
            return $this->userAgent;
        }

        $trimmed = \trim($email);
        if ($trimmed === '') {
            return $this->userAgent;
        }

        return $this->userAgent.' ('.$trimmed.')';
    }
}
