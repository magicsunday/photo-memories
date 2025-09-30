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
use MagicSunday\Memories\Utility\Contract\PoiContextAnalyzerInterface;

use function array_keys;
use function explode;
use function floor;
use function is_array;
use function is_numeric;
use function is_string;
use function ksort;
use function reset;
use function strcmp;
use function strtolower;
use function str_replace;
use function str_contains;
use function trim;
use function uasort;
use function usort;

use const INF;
use const SORT_STRING;

/**
 * Default implementation analysing the POI context for media locations.
 */
final readonly class DefaultPoiContextAnalyzer implements PoiContextAnalyzerInterface
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

    public function resolvePrimaryPoi(Location $location): ?array
    {
        $pois = $location->getPois();
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
            static function (array $left, array $right): int {
                $cmp = $right['score'] <=> $left['score'];
                if ($cmp !== 0) {
                    return $cmp;
                }

                $distanceLeft  = $left['distance'];
                $distanceRight = $right['distance'];
                if ($distanceLeft !== $distanceRight) {
                    $distanceLeft ??= INF;
                    $distanceRight ??= INF;

                    $cmp = $distanceLeft <=> $distanceRight;
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }

                $nameLeft = $left['data']['name'] ?? '';
                $nameRight = $right['data']['name'] ?? '';
                $cmp = strcmp($nameLeft, $nameRight);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return $left['index'] <=> $right['index'];
            }
        );

        /** @var array{
         *     name:?string,
         *     names:array{default:?string,localized:array<string,string>,alternates:list<string>},
         *     categoryKey:?string,
         *     categoryValue:?string,
         *     tags:array<string,string>
         * } $best
         */
        $best = $candidates[0]['data'];

        return $best;
    }

    public function bestLabelForLocation(Location $location): ?string
    {
        $poi = $this->resolvePrimaryPoi($location);
        if ($poi === null) {
            return null;
        }

        $label = $this->preferredPoiLabel($poi);
        if ($label !== null) {
            return $label;
        }

        $categoryValue = $poi['categoryValue'] ?? null;
        if (is_string($categoryValue) && $categoryValue !== '') {
            return $categoryValue;
        }

        return null;
    }

    public function majorityPoiContext(array $members): ?array
    {
        /** @var array<string,array{label:string,categoryKey:?string,categoryValue:?string,tags:array<string,string>,count:int}> $counts */
        $counts = [];

        foreach ($members as $media) {
            $location = $media->getLocation();
            if (!$location instanceof Location) {
                continue;
            }

            $poi = $this->resolvePrimaryPoi($location);
            if ($poi === null) {
                continue;
            }

            $label = $this->preferredPoiLabel($poi) ?? $poi['categoryValue'];
            if (!is_string($label) || $label === '') {
                continue;
            }

            $categoryKey   = $poi['categoryKey'] ?? null;
            $categoryValue = $poi['categoryValue'] ?? null;
            $key           = strtolower($label . '|' . ($categoryKey ?? '') . '|' . ($categoryValue ?? ''));

            if (!isset($counts[$key])) {
                $counts[$key] = [
                    'label'         => $label,
                    'categoryKey'   => $categoryKey,
                    'categoryValue' => $categoryValue,
                    'tags'          => [],
                    'count'         => 0,
                ];
            }

            ++$counts[$key]['count'];

            foreach ($poi['tags'] as $tagKey => $tagValue) {
                if (!is_string($tagKey) || $tagKey === '' || !is_string($tagValue) || $tagValue === '') {
                    continue;
                }

                $counts[$key]['tags'][$tagKey] = $tagValue;
            }
        }

        if ($counts === []) {
            return null;
        }

        uasort(
            $counts,
            static function (array $left, array $right): int {
                $cmp = $right['count'] <=> $left['count'];
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp($left['label'], $right['label']);
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
     * @param array{name:?string,categoryKey:?string,categoryValue:?string,tags:array<string,string>} $poi
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
     * @param array{name:?string,names:array{default:?string,localized:array<string,string>,alternates:list<string>},categoryKey:?string,categoryValue:?string,tags:array<string,string>} $poi
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
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return null;
    }

    /**
     * @param array{default:?string,localized:array<string,string>,alternates:list<string>} $names
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
     * @param array<string,mixed> $poi
     *
     * @return array{
     *     name:?string,
     *     names:array{default:?string,localized:array<string,string>,alternates:list<string>},
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
                if (!is_string($tagKey) || $tagKey === '' || !is_string($tagValue) || $tagValue === '') {
                    continue;
                }

                $tags[$tagKey] = $tagValue;
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
     * @param array{default:?string,localized:array<string,string>,alternates:list<string>}|null $raw
     *
     * @return array{default:?string,localized:array<string,string>,alternates:list<string>}
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

                    if (!is_string($value) || $value === '') {
                        continue;
                    }

                    $localized[$locale] = $value;
                }
            }

            $rawAlternates = $raw['alternates'] ?? [];
            if (is_array($rawAlternates)) {
                foreach ($rawAlternates as $alternate) {
                    if (!is_string($alternate)) {
                        continue;
                    }

                    $trimmed = trim($alternate);
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
     * @param array{default:?string,localized:array<string,string>,alternates:list<string>} $names
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
