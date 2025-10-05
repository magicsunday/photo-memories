<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\Contract\OverpassElementFilterInterface;
use MagicSunday\Memories\Service\Geocoding\Contract\OverpassPrimaryTagResolverInterface;
use MagicSunday\Memories\Service\Geocoding\Contract\OverpassTagSelectorInterface;
use MagicSunday\Memories\Service\Geocoding\Contract\PoiNameExtractorInterface;
use MagicSunday\Memories\Utility\MediaMath;

use function array_slice;
use function array_values;
use function count;
use function is_array;
use function round;
use function usort;

/**
 * Class DefaultOverpassResponseParser
 */
final readonly class DefaultOverpassResponseParser implements OverpassResponseParserInterface
{
    public function __construct(
        private OverpassElementFilterInterface      $elementFilter,
        private OverpassTagSelectorInterface        $tagSelector,
        private OverpassPrimaryTagResolverInterface $primaryTagResolver,
        private PoiNameExtractorInterface           $poiNameExtractor,
    ) {
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

            $normalized = $this->elementFilter->filter($element);
            if ($normalized === null) {
                continue;
            }

            $id = $normalized['id'];
            if (isset($pois[$id])) {
                continue;
            }

            $selection    = $this->tagSelector->select($normalized['tags']);
            $selectedTags = $selection['tags'];
            if ($selectedTags === []) {
                continue;
            }

            $primary = $this->primaryTagResolver->resolve($normalized['tags']);
            if ($primary === null) {
                continue;
            }

            $names = $selection['names'];
            $name  = $this->poiNameExtractor->extract($names);

            $pois[$id] = [
                'id'             => $id,
                'name'           => $name,
                'names'          => $names,
                'categoryKey'    => $primary['key'],
                'categoryValue'  => $primary['value'],
                'lat'            => $normalized['lat'],
                'lon'            => $normalized['lon'],
                'distanceMeters' => round(
                    MediaMath::haversineDistanceInMeters($lat, $lon, $normalized['lat'], $normalized['lon']),
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
}
