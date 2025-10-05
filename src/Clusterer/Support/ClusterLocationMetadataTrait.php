<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function implode;
use function in_array;
use function is_string;
use function mb_convert_case;
use function mb_strtolower;
use function trim;

use const MB_CASE_TITLE;

/**
 * Provides helper functionality to enrich cluster parameters with location metadata.
 *
 * @property LocationHelper $locationHelper
 *
 * @internal
 */
trait ClusterLocationMetadataTrait
{
    /**
     * @param list<Media>          $members
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function appendLocationMetadata(array $members, array $params): array
    {
        $place = $this->locationHelper->majorityLabel($members);
        if (is_string($place)) {
            $normalizedPlace = $this->normalizeLocationComponent($place);
            if ($normalizedPlace !== '') {
                $params['place'] = $normalizedPlace;
            }
        }

        $components = $this->locationHelper->majorityLocationComponents($members);
        if ($components !== []) {
            $locationParts = [];

            $city = $components['city'] ?? null;
            if (is_string($city)) {
                $normalizedCity = $this->normalizeLocationComponent($city);
                if ($normalizedCity !== '') {
                    $params['place_city'] = $normalizedCity;
                    if (!in_array($normalizedCity, $locationParts, true)) {
                        $locationParts[] = $normalizedCity;
                    }
                }
            }

            $region = $components['region'] ?? null;
            if (is_string($region)) {
                $normalizedRegion = $this->normalizeLocationComponent($region);
                if ($normalizedRegion !== '') {
                    $params['place_region'] = $normalizedRegion;
                    if (!in_array($normalizedRegion, $locationParts, true)) {
                        $locationParts[] = $normalizedRegion;
                    }
                }
            }

            $country = $components['country'] ?? null;
            if (is_string($country)) {
                $normalizedCountry = $this->normalizeLocationComponent($country);
                if ($normalizedCountry !== '') {
                    $params['place_country'] = $normalizedCountry;
                    if (!in_array($normalizedCountry, $locationParts, true)) {
                        $locationParts[] = $normalizedCountry;
                    }
                }
            }

            if ($locationParts !== []) {
                $params['place_location'] = implode(', ', $locationParts);
            }
        }

        $poi = $this->locationHelper->majorityPoiContext($members);
        if ($poi !== null) {
            $label = $poi['label'] ?? null;
            if (is_string($label) && $label !== '') {
                $params['poi_label'] = $label;
            }

            $categoryKey = $poi['categoryKey'] ?? null;
            if ($categoryKey !== null) {
                $params['poi_category_key'] = $categoryKey;
            }

            $categoryValue = $poi['categoryValue'] ?? null;
            if ($categoryValue !== null) {
                $params['poi_category_value'] = $categoryValue;
            }

            $tags = $poi['tags'] ?? [];
            if ($tags !== []) {
                $params['poi_tags'] = $tags;
            }
        }

        return $params;
    }

    private function normalizeLocationComponent(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $lowerCase = mb_strtolower($trimmed, 'UTF-8');
        if ($trimmed === $lowerCase) {
            return mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8');
        }

        return $trimmed;
    }
}
