<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;

/**
 * Helper for deriving location keys and display labels from Location entities.
 */
final class LocationHelper
{
    private readonly ?string $preferredLocale;

    public function __construct(?string $preferredLocale = null)
    {
        $this->preferredLocale = $this->normaliseLocale($preferredLocale);
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

        $parts = [];
        $suburb = $loc->getSuburb();
        $city   = $loc->getCity();
        $county = $loc->getCounty();
        $state  = $loc->getState();
        $country= $loc->getCountry();
        $cell   = \method_exists($loc, 'getCell') ? $loc->getCell() : null;

        if ($suburb !== null) { $parts[] = 'suburb:'.$suburb; }
        if ($city   !== null) { $parts[] = 'city:'.$city; }
        if ($county !== null) { $parts[] = 'county:'.$county; }
        if ($state  !== null) { $parts[] = 'state:'.$state; }
        if ($country!== null) { $parts[] = 'country:'.$country; }
        if ($parts === [] && $cell !== null) {
            $parts[] = 'cell:'.$cell;
        }

        return $parts !== [] ? \implode('|', $parts) : null;
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
            $label = $this->resolvePreferredName(
                $this->filterStringMap($poi['names'] ?? null),
                $this->stringOrNull($poi['name'] ?? null),
                $this->stringOrNull($poi['categoryValue'] ?? null)
            );
            if ($label !== null) {
                return $label;
            }
        }

        $city    = $loc->getCity();
        $county  = $loc->getCounty();
        $state   = $loc->getState();
        $country = $loc->getCountry();

        if ($city !== null) {
            return $city;
        }
        if ($county !== null) {
            return $county;
        }
        if ($state !== null) {
            return $state;
        }
        if ($country !== null) {
            return $country;
        }
        return null;
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

        \arsort($count, \SORT_NUMERIC);

        return \array_key_first($count);
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

            $label = $poi['name'] ?? $poi['categoryValue'];
            if ($label === null) {
                continue;
            }

            $catKey   = $poi['categoryKey'] ?? null;
            $catValue = $poi['categoryValue'] ?? null;
            $key = \strtolower($label.'|'.($catKey ?? '').'|'.($catValue ?? ''));

            if (!isset($counts[$key])) {
                $counts[$key] = [
                    'label'        => $label,
                    'categoryKey'  => $catKey,
                    'categoryValue'=> $catValue,
                    'tags'         => [],
                    'count'        => 0,
                ];
            }

            $counts[$key]['count']++;

            foreach ($poi['tags'] as $tagKey => $tagValue) {
                if (\is_string($tagKey) && $tagKey !== '' && \is_string($tagValue) && $tagValue !== '') {
                    $counts[$key]['tags'][$tagKey] = $tagValue;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        \uasort(
            $counts,
            static function (array $a, array $b): int {
                $cmp = $b['count'] <=> $a['count'];
                if ($cmp !== 0) {
                    return $cmp;
                }

                return \strcmp($a['label'], $b['label']);
            }
        );

        $top = \reset($counts);

        return [
            'label'        => $top['label'],
            'categoryKey'  => $top['categoryKey'],
            'categoryValue'=> $top['categoryValue'],
            'tags'         => $top['tags'],
        ];
    }

    /**
     * @return array{
     *     name:?string,
     *     categoryKey:?string,
     *     categoryValue:?string,
     *     tags:array<string,string>,
     *     names:array<string,string>
     * }|null
     */
    private function primaryPoi(Location $loc): ?array
    {
        $pois = $loc->getPois();
        if (!\is_array($pois) || $pois === []) {
            return null;
        }

        foreach ($pois as $poi) {
            if (!\is_array($poi)) {
                continue;
            }

            $categoryKey   = $this->stringOrNull($poi['categoryKey'] ?? null);
            $categoryValue = $this->stringOrNull($poi['categoryValue'] ?? null);

            $names = $this->filterStringMap($poi['names'] ?? null);
            $fallbackName = $this->stringOrNull($poi['name'] ?? null);
            if ($fallbackName !== null && !isset($names['name'])) {
                $names['name'] = $fallbackName;
            }

            $legacyAltName = $this->stringOrNull($poi['alt_name'] ?? null);
            if ($legacyAltName !== null && !isset($names['alt_name'])) {
                $names['alt_name'] = $legacyAltName;
            }

            $name = $this->resolvePreferredName($names, $fallbackName, $categoryValue);

            if ($name === null && $categoryValue === null) {
                continue;
            }

            $tags = $this->filterStringMap($poi['tags'] ?? null);

            return [
                'name'          => $name,
                'categoryKey'   => $categoryKey,
                'categoryValue' => $categoryValue,
                'tags'          => $tags,
                'names'         => $names,
            ];
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private function filterStringMap(mixed $values): array
    {
        $result = [];
        if (!\is_array($values)) {
            return $result;
        }

        foreach ($values as $key => $value) {
            if (\is_string($key) && $key !== '' && \is_string($value) && $value !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function resolvePreferredName(array $names, ?string $fallbackName, ?string $categoryValue): ?string
    {
        foreach ($this->preferredLocaleKeys() as $localeKey) {
            if (isset($names[$localeKey])) {
                return $names[$localeKey];
            }
        }

        if (isset($names['name'])) {
            return $names['name'];
        }

        if (isset($names['alt_name'])) {
            return $names['alt_name'];
        }

        if ($fallbackName !== null) {
            return $fallbackName;
        }

        return $categoryValue;
    }

    /**
     * @return list<string>
     */
    private function preferredLocaleKeys(): array
    {
        if ($this->preferredLocale === null) {
            return [];
        }

        $variants = [
            $this->preferredLocale,
            \str_replace('_', '-', $this->preferredLocale),
            \str_replace('-', '_', $this->preferredLocale),
        ];

        $variants = \array_values(\array_unique(\array_filter(
            $variants,
            static fn (string $value): bool => $value !== ''
        )));

        return \array_map(
            static fn (string $variant): string => 'name:'.$variant,
            $variants
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function normaliseLocale(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $trimmed = \strtolower(\trim($locale));

        return $trimmed !== '' ? $trimmed : null;
    }
}
