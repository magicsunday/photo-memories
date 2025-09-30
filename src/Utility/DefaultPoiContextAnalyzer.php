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
use MagicSunday\Memories\Utility\Contract\PoiLabelResolverInterface;
use MagicSunday\Memories\Utility\Contract\PoiNormalizerInterface;
use MagicSunday\Memories\Utility\Contract\PoiScoringStrategyInterface;

use function is_array;
use function is_numeric;
use function is_string;
use function reset;
use function strcmp;
use function strtolower;
use function uasort;
use function usort;

use const INF;

/**
 * Default implementation analysing the POI context for media locations.
 */
final readonly class DefaultPoiContextAnalyzer implements PoiContextAnalyzerInterface
{
    public function __construct(
        private PoiNormalizerInterface $poiNormalizer,
        private PoiScoringStrategyInterface $poiScorer,
        private PoiLabelResolverInterface $poiLabelResolver,
    ) {
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

            $normalised = $this->poiNormalizer->normalise($poi);
            if ($normalised === null) {
                continue;
            }

            $distance     = $this->distanceOrNull($poi['distanceMeters'] ?? null);
            $candidates[] = [
                'data'     => $normalised,
                'score'    => $this->poiScorer->score($normalised, $distance),
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

        $label = $this->poiLabelResolver->preferredLabel($poi);
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

            $label = $this->poiLabelResolver->preferredLabel($poi) ?? $poi['categoryValue'];
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

    private function distanceOrNull(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
