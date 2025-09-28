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
    private const POI_NAME_BONUS = 100;
    private const POI_CATEGORY_VALUE_BONUS = 30;
    private const POI_WIKIDATA_BONUS = 120;
    private const POI_DISTANCE_PENALTY_DIVISOR = 25;

    /**
     * Tag specific weightings favouring more significant POIs.
     *
     * @var array<string,int>
     */
    private const POI_TAG_WEIGHTS = [
        'tourism' => 600,
        'historic' => 450,
        'man_made' => 220,
        'leisure' => 140,
        'natural' => 140,
        'place' => 130,
        'amenity' => 100,
        'building' => 70,
        'sport' => 60,
        'shop' => 40,
        'landuse' => 25,
    ];

    /**
     * Category key bonuses stacked on top of the tag weights.
     *
     * @var array<string,int>
     */
    private const POI_CATEGORY_KEY_BONUS = [
        'tourism' => 220,
        'historic' => 180,
        'man_made' => 150,
        'leisure' => 90,
        'amenity' => 80,
        'natural' => 90,
        'place' => 80,
        'building' => 60,
        'sport' => 50,
        'shop' => 40,
    ];

    /**
     * Additional bonuses for specific tag/value combinations.
     *
     * @var array<string,int>
     */
    private const POI_TAG_VALUE_BONUS = [
        'man_made:tower' => 260,
    ];

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
            $label = $poi['name'] ?? $poi['categoryValue'];
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
     * Returns the highest ranked POI for the location using tag-based weighting.
     *
     * @return array{name:?string, categoryKey:?string, categoryValue:?string, tags:array<string,string>}|null
     */
    private function primaryPoi(Location $loc): ?array
    {
        $pois = $loc->getPois();
        if (!\is_array($pois) || $pois === []) {
            return null;
        }

        $candidates = [];
        foreach ($pois as $index => $poi) {
            if (!\is_array($poi)) {
                continue;
            }

            $normalised = $this->normalisePoi($poi);
            if ($normalised === null) {
                continue;
            }

            $distance = $this->distanceOrNull($poi['distanceMeters'] ?? null);
            $candidates[] = [
                'data' => $normalised,
                'score' => $this->computePoiWeight($normalised, $distance),
                'distance' => $distance,
                'index' => $index,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        \usort(
            $candidates,
            static function (array $a, array $b): int {
                $cmp = $b['score'] <=> $a['score'];
                if ($cmp !== 0) {
                    return $cmp;
                }

                $distanceA = $a['distance'];
                $distanceB = $b['distance'];
                if ($distanceA !== $distanceB) {
                    $distanceA ??= \INF;
                    $distanceB ??= \INF;

                    $cmp = $distanceA <=> $distanceB;
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }

                $nameA = $a['data']['name'] ?? '';
                $nameB = $b['data']['name'] ?? '';
                $cmp = \strcmp($nameA, $nameB);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return $a['index'] <=> $b['index'];
            }
        );

        /** @var array{name:?string, categoryKey:?string, categoryValue:?string, tags:array<string,string>} $best */
        $best = $candidates[0]['data'];

        return $best;
    }

    /**
     * @param array<string,mixed> $poi
     * @return array{name:?string, categoryKey:?string, categoryValue:?string, tags:array<string,string>}|null
     */
    private function normalisePoi(array $poi): ?array
    {
        $name = \is_string($poi['name'] ?? null) && $poi['name'] !== '' ? $poi['name'] : null;
        $categoryKey = \is_string($poi['categoryKey'] ?? null) && $poi['categoryKey'] !== '' ? $poi['categoryKey'] : null;
        $categoryValue = \is_string($poi['categoryValue'] ?? null) && $poi['categoryValue'] !== '' ? $poi['categoryValue'] : null;

        if ($name === null && $categoryValue === null) {
            return null;
        }

        $tags = [];
        $rawTags = $poi['tags'] ?? null;
        if (\is_array($rawTags)) {
            foreach ($rawTags as $tagKey => $tagValue) {
                if (\is_string($tagKey) && $tagKey !== '' && \is_string($tagValue) && $tagValue !== '') {
                    $tags[$tagKey] = $tagValue;
                }
            }
        }

        return [
            'name' => $name,
            'categoryKey' => $categoryKey,
            'categoryValue' => $categoryValue,
            'tags' => $tags,
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
            $score += self::POI_TAG_VALUE_BONUS[$tagKey.':'.$tagValue] ?? 0;
        }

        if (isset($poi['tags']['wikidata'])) {
            $score += self::POI_WIKIDATA_BONUS;
        }

        if ($distance !== null && $distance > 0.0) {
            $score -= (int) \floor($distance / self::POI_DISTANCE_PENALTY_DIVISOR);
        }

        return $score;
    }

    private function distanceOrNull(mixed $value): ?float
    {
        if (\is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
