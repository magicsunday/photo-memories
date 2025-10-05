<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_filter;
use function array_values;
use function count;

/**
 * Shared helpers for filtering media collections before clustering.
 */
trait MediaFilterTrait
{
    /**
     * Indicates whether the media item can participate in clustering.
     */
    private function isEligibleMedia(Media $media): bool
    {
        return $media->isNoShow() === false && $media->isLowQuality() === false;
    }

    /**
     * @param list<Media> $items
     *
     * @return list<Media>
     */
    private function filterTimestampedItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn (Media $m): bool => $this->isEligibleMedia($m)
                && $m->getTakenAt() instanceof DateTimeImmutable
        ));
    }

    /**
     * Applies an additional predicate after filtering for timestamped media.
     *
     * @param list<Media>          $items
     * @param callable(Media):bool $predicate
     *
     * @return list<Media>
     */
    private function filterTimestampedItemsBy(array $items, callable $predicate): array
    {
        return array_values(array_filter(
            $this->filterTimestampedItems($items),
            $predicate
        ));
    }

    /**
     * @param list<Media> $items
     *
     * @return list<Media>
     */
    private function filterGpsItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn (Media $m): bool => $this->isEligibleMedia($m)
                && $m->getGpsLat() !== null
                && $m->getGpsLon() !== null
        ));
    }

    /**
     * @param list<Media> $items
     *
     * @return list<Media>
     */
    private function filterTimestampedGpsItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn (Media $m): bool => $this->isEligibleMedia($m)
                && $m->getTakenAt() instanceof DateTimeImmutable
                && $m->getGpsLat() !== null
                && $m->getGpsLon() !== null
        ));
    }

    /**
     * Applies an additional predicate after filtering for timestamped media with GPS.
     *
     * @param list<Media>          $items
     * @param callable(Media):bool $predicate
     *
     * @return list<Media>
     */
    private function filterTimestampedGpsItemsBy(array $items, callable $predicate): array
    {
        return array_values(array_filter(
            $this->filterTimestampedGpsItems($items),
            $predicate
        ));
    }

    /**
     * Filters grouped media collections by enforcing a minimum member count per group.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, list<Media>> $groups
     *
     * @return array<TKey, list<Media>>
     */
    private function filterGroupsByMinItems(array $groups, int $minItemsPerGroup): array
    {
        return array_filter(
            $groups,
            static fn (array $members): bool => count($members) >= $minItemsPerGroup
        );
    }

    /**
     * Ensures list-based buckets meet a minimum size and reindexes the resulting array.
     *
     * @param list<list<Media>> $groups
     *
     * @return list<list<Media>>
     */
    private function filterListsByMinItems(array $groups, int $minItemsPerGroup): array
    {
        return array_values($this->filterGroupsByMinItems($groups, $minItemsPerGroup));
    }

    /**
     * Filters associative media groups via a custom predicate while preserving keys.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, list<Media>>   $groups
     * @param callable(list<Media>):bool $predicate
     *
     * @return array<TKey, list<Media>>
     */
    private function filterGroups(array $groups, callable $predicate): array
    {
        return array_filter($groups, $predicate);
    }

    /**
     * Filters associative media groups via a custom predicate that also receives the key.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, list<Media>>         $groups
     * @param callable(list<Media>, TKey):bool $predicate
     *
     * @return array<TKey, list<Media>>
     */
    private function filterGroupsWithKeys(array $groups, callable $predicate): array
    {
        return array_filter($groups, $predicate, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Removes spatial outliers from a list of media using a lightweight DBSCAN run.
     *
     * @param list<Media> $items
     *
     * @return list<Media>
     */
    private function filterGpsOutliers(array $items, float $radiusKm, int $minSamples): array
    {
        if ($items === []) {
            return $items;
        }

        if ($minSamples < 2) {
            return $items;
        }

        if (count($items) < $minSamples) {
            return $items;
        }

        $coordinates = [];
        foreach ($items as $media) {
            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();
            if ($lat === null || $lon === null) {
                return $items;
            }

            $coordinates[] = [$lat, $lon];
        }

        $count        = count($coordinates);
        $labels       = array_fill(0, $count, null);
        $clusterId    = 0;
        $radiusMeters = $radiusKm * 1000.0;

        $regionQuery = static function (array $points, int $index, float $radius): array {
            $neighbors = [];
            [$latA, $lonA] = $points[$index];

            foreach ($points as $idx => [$latB, $lonB]) {
                $distance = MediaMath::haversineDistanceInMeters($latA, $lonA, $latB, $lonB);
                if ($distance <= $radius) {
                    $neighbors[] = $idx;
                }
            }

            return $neighbors;
        };

        for ($i = 0; $i < $count; ++$i) {
            if ($labels[$i] !== null) {
                continue;
            }

            $neighbors = $regionQuery($coordinates, $i, $radiusMeters);
            if (count($neighbors) < $minSamples) {
                $labels[$i] = -1;
                continue;
            }

            $labels[$i] = $clusterId;
            $queue      = $neighbors;
            for ($queueIndex = 0; $queueIndex < count($queue); ++$queueIndex) {
                $neighborIndex = $queue[$queueIndex];

                if ($labels[$neighborIndex] === -1) {
                    $labels[$neighborIndex] = $clusterId;
                }

                if ($labels[$neighborIndex] !== null) {
                    continue;
                }

                $labels[$neighborIndex] = $clusterId;
                $neighborNeighbors      = $regionQuery($coordinates, $neighborIndex, $radiusMeters);

                if (count($neighborNeighbors) >= $minSamples) {
                    foreach ($neighborNeighbors as $newIndex) {
                        $queue[] = $newIndex;
                    }
                }
            }

            ++$clusterId;
        }

        $result = [];
        foreach ($items as $index => $media) {
            if ($labels[$index] !== null && $labels[$index] >= 0) {
                $result[] = $media;
            }
        }

        if ($result === []) {
            return $items;
        }

        return array_values($result);
    }
}
