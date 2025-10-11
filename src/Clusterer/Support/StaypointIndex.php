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

use function spl_object_id;
use function sprintf;

/**
 * Provides fast lookups between media items and their staypoint keys.
 */
final class StaypointIndex
{
    /**
     * @param array<int, string> $mediaToKey
     * @param array<string, int> $counts
     */
    private function __construct(
        private array $mediaToKey,
        private array $counts,
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * Builds a staypoint index for the provided day summary members.
     *
     * @param string                                                   $date
     * @param list<array{lat:float,lon:float,start:int,end:int,dwell:int}> $staypoints
     * @param list<Media>                                               $members
     */
    public static function build(string $date, array $staypoints, array $members): self
    {
        if ($staypoints === [] || $members === []) {
            return self::empty();
        }

        $mediaToKey = [];
        $counts     = [];

        foreach ($members as $media) {
            $timestamp = $media->getTakenAt()?->getTimestamp();
            if ($timestamp === null) {
                continue;
            }

            foreach ($staypoints as $staypoint) {
                $start = (int) $staypoint['start'];
                $end   = (int) $staypoint['end'];

                if ($timestamp < $start || $timestamp > $end) {
                    continue;
                }

                $key = self::createKey($date, $start, $end);

                $mediaToKey[spl_object_id($media)] = $key;
                $counts[$key]                      = ($counts[$key] ?? 0) + 1;

                break;
            }
        }

        return new self($mediaToKey, $counts);
    }

    public static function createKeyFromStaypoint(string $date, array $staypoint): string
    {
        return self::createKey($date, (int) $staypoint['start'], (int) $staypoint['end']);
    }

    public function get(Media $media): ?string
    {
        $id = spl_object_id($media);

        return $this->mediaToKey[$id] ?? null;
    }

    public function has(Media $media): bool
    {
        $id = spl_object_id($media);

        return isset($this->mediaToKey[$id]);
    }

    /**
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return $this->counts;
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->mediaToKey;
    }

    private static function createKey(string $date, int $start, int $end): string
    {
        return sprintf('%s:%d:%d', $date, $start, $end);
    }
}
