<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_keys;
use function array_map;
use function array_values;
use function assert;
use function count;
use function usort;

/**
 * Aggregates each season across multiple years into a memory
 * (e.g., "Sommer im Laufe der Jahre").
 */
final readonly class SeasonOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private int $minYears = 3,
        // Minimum total members per season bucket across all years considered.
        private int $minItemsPerSeason = 30,
    ) {
        if ($this->minYears < 1) {
            throw new InvalidArgumentException('minYears must be >= 1.');
        }

        if ($this->minItemsPerSeason < 1) {
            throw new InvalidArgumentException('minItemsPerSeason must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'season_over_years';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $groups */
        $groups = [];

        foreach ($timestamped as $m) {
            $t = $m->getTakenAt();
            assert($t instanceof DateTimeImmutable);
            $month  = (int) $t->format('n');
            $season = match (true) {
                $month >= 3 && $month <= 5  => 'Frühling',
                $month >= 6 && $month <= 8  => 'Sommer',
                $month >= 9 && $month <= 11 => 'Herbst',
                default                     => 'Winter',
            };
            $groups[$season] ??= [];
            $groups[$season][] = $m;
        }

        $eligibleSeasons = $this->filterGroupsByMinItems($groups, $this->minItemsPerSeason);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleSeasons as $season => $list) {
            /** @var array<int,bool> $years */
            $years = [];
            foreach ($list as $m) {
                $years[(int) $m->getTakenAt()->format('Y')] = true;
            }

            if (count($years) < $this->minYears) {
                continue;
            }

            usort($list, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'label'      => $season . ' im Laufe der Jahre',
                    'years'      => array_values(array_keys($years)),
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }
}
