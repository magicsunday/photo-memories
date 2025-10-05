<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use function array_merge;
use function array_unique;
use function array_values;
use function is_array;
use function is_int;
use function is_string;

/**
 * Holds configuration for Overpass tag filtering.
 */
final class OverpassTagConfiguration
{
    /**
     * Default Overpass tag filters considered for POI categorisation.
     * Represented as a map of tag keys to lists of allowed values.
     */
    private const array DEFAULT_ALLOWED_COMBINATIONS = [
        ['tourism' => ['attraction', 'viewpoint', 'museum', 'gallery']],
        ['historic' => ['monument', 'castle', 'memorial']],
        ['man_made' => ['tower', 'lighthouse']],
        ['leisure'  => ['park', 'garden']],
        ['natural'  => ['peak', 'cliff']],
    ];

    /**
     * @var list<array<string,list<string>>>
     */
    private array $allowedTagCombinations;

    /**
     * @var array<string,list<string>>
     */
    private array $allowedTagMap;

    /**
     * @param array<mixed> $additionalAllowedTags
     */
    public function __construct(array $additionalAllowedTags = [])
    {
        [$this->allowedTagCombinations, $this->allowedTagMap] = $this->mergeAllowedTags($additionalAllowedTags);
    }

    /**
     * @return list<array<string,list<string>>>
     */
    public function getAllowedTagCombinations(): array
    {
        return $this->allowedTagCombinations;
    }

    /**
     * @return array<string,list<string>>
     */
    public function getAllowedTagMap(): array
    {
        return $this->allowedTagMap;
    }

    /**
     * @param array<mixed> $additional
     *
     * @return array{0:list<array<string,list<string>>>,1:array<string,list<string>>}
     */
    private function mergeAllowedTags(array $additional): array
    {
        $combinations = [];

        foreach (self::DEFAULT_ALLOWED_COMBINATIONS as $combination) {
            $normalized = $this->normalizeCombination($combination);
            if ($normalized !== []) {
                $combinations[] = $normalized;
            }
        }

        foreach ($additional as $key => $values) {
            if (is_string($key)) {
                $normalized = $this->normalizeCombination([$key => $values]);
            } elseif (is_array($values)) {
                $normalized = $this->normalizeCombination($values);
            } else {
                continue;
            }

            if ($normalized === []) {
                continue;
            }

            $combinations[] = $normalized;
        }

        /** @var array<string,list<string>> $flat */
        $flat = [];

        foreach ($combinations as $combination) {
            foreach ($combination as $key => $values) {
                if (!isset($flat[$key])) {
                    $flat[$key] = [];
                }

                $flat[$key] = array_values(array_unique(array_merge($flat[$key], $values)));
            }
        }

        return [$combinations, $flat];
    }

    /**
     * @param array<mixed> $combination
     *
     * @return array<string,list<string>>
     */
    private function normalizeCombination(array $combination): array
    {
        /** @var array<string,list<string>> $normalized */
        $normalized = [];

        foreach ($combination as $key => $values) {
            if (!is_string($key)) {
                continue;
            }

            if (is_string($values)) {
                $values = [$values];
            }

            if (!is_array($values)) {
                continue;
            }

            $valueList = [];
            foreach ($values as $value) {
                $value = $this->stringOrNull($value);
                if ($value === null) {
                    continue;
                }

                $valueList[] = $value;
            }

            if ($valueList === []) {
                continue;
            }

            $normalized[$key] = array_values(array_unique($valueList));
        }

        return $normalized;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }
}
