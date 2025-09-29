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

use function array_filter;
use function array_values;
use function count;

/**
 * Shared helpers for filtering media collections before clustering.
 */
trait MediaFilterTrait
{
    /**
     * @param list<Media> $items
     *
     * @return list<Media>
     */
    private function filterTimestampedItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (Media $m): bool => $m->getTakenAt() instanceof DateTimeImmutable
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
            static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null
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
            static fn (Media $m): bool => $m->getTakenAt() instanceof DateTimeImmutable
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
}
