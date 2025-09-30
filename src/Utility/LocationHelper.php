<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;

use function array_key_first;
use function array_keys;
use function arsort;
use function explode;
use function floor;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function ksort;
use function method_exists;
use function reset;
use function str_contains;
use function str_replace;
use function strcmp;
use function strtolower;
use function trim;
use function uasort;
use function usort;

use const INF;
use const SORT_NUMERIC;
use const SORT_STRING;

/**
 * Helper for deriving location keys and display labels from Location entities.
 */
final readonly class LocationHelper
{
    private const int POI_NAME_BONUS = 100;

    private const int POI_CATEGORY_VALUE_BONUS = 30;

    private const int POI_WIKIDATA_BONUS = 120;

    private const int POI_DISTANCE_PENALTY_DIVISOR = 25;

    /**
     * Tag specific weightings favouring more significant POIs.
     *
     * @var array<string,int>
     */
    private const array POI_TAG_WEIGHTS = [
        'tourism'  => 600,
        'historic' => 450,
        'man_made' => 220,
        'leisure'  => 140,
        'natural'  => 140,
        'place'    => 130,
        'sport'    => 60,
        'landuse'  => 25,
    ];

    /**
     * Category key bonuses stacked on top of the tag weights.
     *
     * @var array<string,int>
     */
    private const array POI_CATEGORY_KEY_BONUS = [
        'tourism'  => 220,
        'historic' => 180,
        'man_made' => 150,
        'leisure'  => 90,
        'natural'  => 90,
        'place'    => 80,
        'sport'    => 50,
    ];

    /**
     * Additional bonuses for specific tag/value combinations.
     *
     * @var array<string,int>
     */
    private const array POI_TAG_VALUE_BONUS = [
        'man_made:tower' => 260,
    ];

    /**
     * @var list<string>
     */
    private array $preferredLocaleKeys;

    public function __construct(?string $preferredLocale = null)
    {
        $preferredLocale = $preferredLocale !== null ? trim($preferredLocale) : null;
        if ($preferredLocale === '') {
            $preferredLocale = null;
        }

        $this->preferredLocaleKeys = $this->buildPreferredLocaleKeys($preferredLocale);
    }

    /**
     * Returns a stable locality key for grouping.
     * Priority: suburb -> city -> county -> state -> country -> cell.
     */
    public function localityKey(?Location $loc): ?string
    {
        if (!$loc instanceof Location) {
            return null;
        }

        $parts   = [];
        $suburb  = $loc->getSuburb();
        $city    = $loc->getCity();
        $county  = $loc->getCounty();
        $state   = $loc->getState();
        $country = $loc->getCountry();
        $cell    = method_exists($loc, 'getCell') ? $loc->getCell() : null;

        if ($suburb !== null) {
            $parts[] = 'suburb:' . $suburb;
        }

        if ($city !== null) {
            $parts[] = 'city:' . $city;
        }

        if ($county !== null) {
            $parts[] = 'county:' . $county;
        }

        if ($state !== null) {
            $parts[] = 'state:' . $state;
        }

        if ($country !== null) {
            $parts[] = 'country:' . $country;
        }

        if ($parts === [] && $cell !== null) {
            $parts[] = 'cell:' . $cell;
        }

        return $parts !== [] ? implode('|', $parts) : null;
    }

    /**
     * Short human label for titles.
     * Prefers "Suburb, City" -> "City" -> "County" -> "State" -> "Country".
     */
    public function displayLabel(?Location $loc): ?string
    {
        if (!$loc instanceof Location) {
            return null;
        }

        $poi = $this->primaryPoi($loc);
        if ($poi !== null) {
            $label = $this->preferredPoiLabel($poi) ?? $poi['categoryValue'];
            if ($label !== null) {
                return $label;
            }
        }

        $city    = $loc->getCity();
        $county  = $loc->getCounty();
        $state   = $loc->getState();
        $country = $loc->getCountry();

        return (($city ?? $county) ?? $state) ?? $country;
    }

    /**
     * Convenience wrappers reading from Media directly.
     */
    public function localityKeyForMedia(Media $m): ?string
    {
        return $this->localityKey($m->getLocation());
    }

    public function labelForMedia(Media $m): ?string
    {
        return $this->displayLabel($m->getLocation());
    }

    /**
     * Returns the majority location label across members (stable for titles).
     *
     * @param list<Media> $members
     */
    public function majorityLabel(array $members): ?string
    {
        $poiContext = $this->majorityPoiContext($members);
        if ($poiContext !== null) {
            return $poiContext['label'];
        }

        /** @var array<string,int> $count */
        $count = [];
        foreach ($members as $m) {
            $label = $this->labelForMedia($m);
            if ($label === null) {
                continue;
            }

            $count[$label] = ($count[$label] ?? 0) + 1;
        }

        if ($count === []) {
            return null;
        }

        arsort($count, SORT_NUMERIC);

        return array_key_first($count);
    }

    /**
     * Returns the dominant location components across all members.
     *
     * @param list<Media> $members
     *
     * @return array{country?:string,region?:string,city?:string}
     */
    public function majorityLocationComponents(array $members): array
    {
        $counts = [
            'country' => [],
            'region'  => [],
            'city'    => [],
        ];

        foreach ($members as $media) {
            $location = $media->getLocation();
            if (!$location instanceof Location) {
                continue;
            }

            $this->collectComponent($counts['country'], $location->getCountry());
            $this->collectComponent($counts['region'], $location->getState());
            $this->collectComponent($counts['city'], $location->getCity());
        }

        $result = [];

        foreach ($counts as $component => $tallies) {
            $majority = $this->pickMajorityValue($tallies);
            if ($majority !== null) {
                $result[$component] = $majority;
            }
        }

        return $result;
    }

    /**
     * Returns true if two medias share the same locality key.
     */
    public function sameLocality(Media $a, Media $b): bool
    {
        $ka = $this->localityKeyForMedia($a);
        $kb = $this->localityKeyForMedia($b);

        return $ka !== null && $kb !== null && $ka === $kb;
    }

    /**
     * Returns metadata about the dominant POI within a media collection.
     *
     * @param list<Media> $members
     *
     * @return array{label:string, categoryKey:?string, categoryValue:?string, tags:array<string,string>}|null
     */
    public function majorityPoiContext(array $members): ?array
    {
        /** @var array<string,array{label:string, categoryKey:?string, categoryValue:?string, tags:array<string,string>, count:int}> $counts */
        $counts = [];

        foreach ($members as $m) {
            $loc = $m->getLocation();
            if (!$loc instanceof Location) {
                continue;
            }

            $poi = $this->primaryPoi($loc);
            if ($poi === null) {
                continue;
            }

            $label = $this->preferredPoiLabel($poi) ?? $poi['categoryValue'];
            if ($label === null) {
                continue;
            }

            $catKey   = $poi['categoryKey'] ?? null;
            $catValue = $poi['categoryValue'] ?? null;
            $key      = strtolower($label . '|' . ($catKey ?? '') . '|' . ($catValue ?? ''));

            if (!isset($counts[$key])) {
                $counts[$key] = [
                    'label'         => $label,
                    'categoryKey'   => $catKey,
                    'categoryValue' => $catValue,
                    'tags'          => [],
                    'count'         => 0,
                ];
            }

            ++$counts[$key]['count'];

            foreach ($poi['tags'] as $tagKey => $tagValue) {
                if (is_string($tagKey) && $tagKey !== '' && is_string($tagValue) && $tagValue !== '') {
                    $counts[$key]['tags'][$tagKey] = $tagValue;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        uasort(
            $counts,
            static function (array $a, array $b): int {
                $cmp = $b['count'] <=> $a['count'];
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp($a['label'], $b['label']);
            }
        );

        $top = reset($counts);

        return [
            'label'         => $top['label'],
            'categoryKey'   => $top['categoryKey'],
            'categoryValue' => $top['categoryValue'],
            'tags'          => $top['tags'],
        ];
    }

    /**
     * @param array<string,array{count:int}> $bucket
     */
    private function pickMajorityValue(array $bucket): ?string
    {
        $winnerValue = null;
        $winnerCount = -1;

        foreach ($bucket as $value => $payload) {
            $count = $payload['count'];
            if ($count > $winnerCount) {
                $winnerCount = $count;
                $winnerValue = $value;

                continue;
            }

            if ($count === $winnerCount && $winnerValue !== null && strcmp($value, $winnerValue) < 0) {
                $winnerValue = $value;
            }
        }

        return $winnerValue;
    }

    /**
     * @param array<string,array{count:int}> &$bucket
     */
    private function collectComponent(array &$bucket, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return;
        }

        if (!isset($bucket[$normalized])) {
            $bucket[$normalized] = ['count' => 0];
        }

        ++$bucket[$normalized]['count'];
    }

    /**
     * Returns the highest ranked POI for the location using tag-based weighting.
     *
     * @return array{
     *     name:?string,
     *     names:array{default:?string, localized:array<string,string>, alternates:list<string>},
     *     categoryKey:?string,
     *     categoryValue:?string,
     *     tags:array<string,string>
     * }|null
     */
    private function primaryPoi(Location $loc): ?array
    {
        $pois = $loc->getPois();
        if (!is_array($pois) || $pois === []) {
            return null;
        }

        $candidates = [];
        foreach ($pois as $index => $poi) {
            if (!is_array($poi)) {
                continue;
            }

            $normalised = $this->normalisePoi($poi);
            if ($normalised === null) {
                continue;
            }

            $distance     = $this->distanceOrNull($poi['distanceMeters'] ?? null);
            $candidates[] = [
                'data'     => $normalised,
                'score'    => $this->computePoiWeight($normalised, $distance),
                'distance' => $distance,
                'index'    => $index,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static function (array $a, array $b): int {
                $cmp = $b['score'] <=> $a['score'];
                if ($cmp !== 0) {
                    return $cmp;
                }

                $distanceA = $a['distance'];
                $distanceB = $b['distance'];
                if ($distanceA !== $distanceB) {
                    $distanceA ??= INF;
                    $distanceB ??= INF;

                    $cmp = $distanceA <=> $distanceB;
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }

                $nameA = $a['data']['name'] ?? '';
                $nameB = $b['data']['name'] ?? '';
                $cmp   = strcmp($nameA, $nameB);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return $a['index'] <=> $b['index'];
            }
        );

        /** @var array{
         *     name:?string,
         *     names:array{default:?string, localized:array<string,string>, alternates:list<string>},
         *     categoryKey:?string,
         *     categoryValue:?string,
         *     tags:array<string,string>
         * } $best
         */
        $best = $candidates[0]['data'];

        return $best;
    }

    /**
     * @param array<string,mixed> $poi
     *
     * @return array{
     *     name:?string,
     *     names:array{default:?string, localized:array<string,string>, alternates:list<string>},
     *     categoryKey:?string,
     *     categoryValue:?string,
     *     tags:array<string,string>
     * }|null
     */
    private function normalisePoi(array $poi): ?array
    {
        $name  = is_string($poi['name'] ?? null) && $poi['name'] !== '' ? $poi['name'] : null;
        $names = $this->normaliseNames($poi['names'] ?? null, $name);
        if ($name === null) {
            $name = $this->coalesceName($names);
        }

        $categoryKey   = is_string($poi['categoryKey'] ?? null) && $poi['categoryKey'] !== '' ? $poi['categoryKey'] : null;
        $categoryValue = is_string($poi['categoryValue'] ?? null) && $poi['categoryValue'] !== '' ? $poi['categoryValue'] : null;

        if ($name === null && $categoryValue === null) {
            return null;
        }

        $tags    = [];
        $rawTags = $poi['tags'] ?? null;
        if (is_array($rawTags)) {
            foreach ($rawTags as $tagKey => $tagValue) {
                if (is_string($tagKey) && $tagKey !== '' && is_string($tagValue) && $tagValue !== '') {
                    $tags[$tagKey] = $tagValue;
                }
            }
        }

        return [
            'name'          => $name,
            'names'         => $names,
            'categoryKey'   => $categoryKey,
            'categoryValue' => $categoryValue,
            'tags'          => $tags,
        ];
    }

    /**
     * @param array{name:?string, categoryKey:?string, categoryValue:?string, tags:array<string,string>} $poi
     */
    private function computePoiWeight(array $poi, ?float $distance): int
    {
        $score = 0;

        if ($poi['name'] !== null) {
            $score += self::POI_NAME_BONUS;
        }

        if ($poi['categoryValue'] !== null) {
            $score += self::POI_CATEGORY_VALUE_BONUS;
        }

        $categoryKey = $poi['categoryKey'];
        if ($categoryKey !== null) {
            $score += self::POI_CATEGORY_KEY_BONUS[$categoryKey] ?? 0;
        }

        foreach ($poi['tags'] as $tagKey => $tagValue) {
            $score += self::POI_TAG_WEIGHTS[$tagKey] ?? 0;
            $score += self::POI_TAG_VALUE_BONUS[$tagKey . ':' . $tagValue] ?? 0;
        }

        if (isset($poi['tags']['wikidata'])) {
            $score += self::POI_WIKIDATA_BONUS;
        }

        if ($distance !== null && $distance > 0.0) {
            $score -= (int) floor($distance / self::POI_DISTANCE_PENALTY_DIVISOR);
        }

        return $score;
    }

    private function distanceOrNull(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param array{name:?string, names:array{default:?string, localized:array<string,string>, alternates:list<string>}, categoryKey:?string, categoryValue:?string, tags:array<string,string>} $poi
     */
    private function preferredPoiLabel(array $poi): ?string
    {
        $names = $poi['names'] ?? null;
        if (is_array($names)) {
            $label = $this->labelFromNames($names);
            if ($label !== null) {
                return $label;
            }
        }

        $name = $poi['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param array{default:?string, localized:array<string,string>, alternates:list<string>} $names
     */
    private function labelFromNames(array $names): ?string
    {
        $localized = $names['localized'] ?? [];
        if (is_array($localized) && $localized !== []) {
            foreach ($this->preferredLocaleKeys as $key) {
                $value = $localized[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        $default = $names['default'] ?? null;
        if (is_string($default) && $default !== '') {
            return $default;
        }

        if (is_array($localized)) {
            foreach ($localized as $value) {
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        $alternates = $names['alternates'] ?? [];
        if (is_array($alternates)) {
            foreach ($alternates as $alternate) {
                if (is_string($alternate) && $alternate !== '') {
                    return $alternate;
                }
            }
        }

        return null;
    }

    /**
     * @param array{default:?string, localized:array<string,string>, alternates:list<string>}|null $raw
     *
     * @return array{default:?string, localized:array<string,string>, alternates:list<string>}
     */
    private function normaliseNames(?array $raw, ?string $fallbackDefault): array
    {
        $default    = $fallbackDefault;
        $localized  = [];
        $alternates = [];

        if (is_array($raw)) {
            $rawDefault = $raw['default'] ?? null;
            if (is_string($rawDefault) && $rawDefault !== '') {
                $default = $rawDefault;
            }

            $rawLocalized = $raw['localized'] ?? [];
            if (is_array($rawLocalized)) {
                foreach ($rawLocalized as $locale => $value) {
                    if (!is_string($locale)) {
                        continue;
                    }

                    $locale = strtolower(str_replace(' ', '_', $locale));
                    if ($locale === '') {
                        continue;
                    }

                    if (!is_string($value)) {
                        continue;
                    }

                    if ($value === '') {
                        continue;
                    }

                    $localized[$locale] = $value;
                }
            }

            $rawAlternates = $raw['alternates'] ?? [];
            if (is_array($rawAlternates)) {
                foreach ($rawAlternates as $alt) {
                    if (!is_string($alt)) {
                        continue;
                    }

                    $trimmed = trim($alt);
                    if ($trimmed === '') {
                        continue;
                    }

                    $alternates[$trimmed] = true;
                }
            }
        }

        if ($localized !== []) {
            ksort($localized, SORT_STRING);
        }

        /** @var list<string> $alternateList */
        $alternateList = array_keys($alternates);

        return [
            'default'    => $default,
            'localized'  => $localized,
            'alternates' => $alternateList,
        ];
    }

    /**
     * @param array{default:?string, localized:array<string,string>, alternates:list<string>} $names
     */
    private function coalesceName(array $names): ?string
    {
        $default = $names['default'];
        if (is_string($default) && $default !== '') {
            return $default;
        }

        foreach ($names['localized'] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        foreach ($names['alternates'] as $alternate) {
            if (is_string($alternate) && $alternate !== '') {
                return $alternate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function buildPreferredLocaleKeys(?string $locale): array
    {
        if ($locale === null) {
            return [];
        }

        $lower      = strtolower($locale);
        $normalized = str_replace('_', '-', $lower);

        $candidates = [];
        if ($normalized !== '') {
            $candidates[] = $normalized;
            $candidates[] = str_replace('-', '_', $normalized);
        }

        if (str_contains($normalized, '-')) {
            $language = explode('-', $normalized)[0];
            if ($language !== '') {
                $candidates[] = $language;
            }
        } elseif ($normalized !== '') {
            $candidates[] = $normalized;
        }

        $filtered = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }

            $filtered[$trimmed] = true;
        }

        /** @var list<string> $keys */
        $keys = array_keys($filtered);

        return $keys;
    }
}
