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
        if (is_string($place) && $place !== '') {
            $params['place'] = $place;
        }

        $components = $this->locationHelper->majorityLocationComponents($members);
        if ($components !== []) {
            $locationParts = [];

            $city = $components['city'] ?? null;
            if (is_string($city) && $city !== '') {
                $params['place_city'] = $city;
                if (!in_array($city, $locationParts, true)) {
                    $locationParts[] = $city;
                }
            }

            $region = $components['region'] ?? null;
            if (is_string($region) && $region !== '') {
                $params['place_region'] = $region;
                if (!in_array($region, $locationParts, true)) {
                    $locationParts[] = $region;
                }
            }

            $country = $components['country'] ?? null;
            if (is_string($country) && $country !== '') {
                $params['place_country'] = $country;
                if (!in_array($country, $locationParts, true)) {
                    $locationParts[] = $country;
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
}
