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
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\CalendarFeatureHelper;

use function array_keys;
use function array_values;
use function assert;
use function count;
use function mb_strtolower;
use function usort;

/**
 * Aggregates each season across multiple years into a memory
 * (e.g., "Sommer im Laufe der Jahre").
 */
final readonly class SeasonOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;

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
            $season = $this->resolveSeason($m);

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
            $centroid = $this->computeCentroid($list);
            $time     = $this->computeTimeRange($list);

            $params = [
                'label'      => $season . ' im Laufe der Jahre',
                'years'      => array_values(array_keys($years)),
                'time_range' => $time,
            ];

            $tags = $this->collectDominantTags($list);
            if ($tags !== []) {
                $params = [...$params, ...$tags];
            }

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: $this->toMemberIds($list)
            );
        }

        return $out;
    }

    private function resolveSeason(Media $media): string
    {
        $takenAt = $media->getTakenAt();
        assert($takenAt instanceof DateTimeImmutable);

        $month            = (int) $takenAt->format('n');
        $calendarFeatures = CalendarFeatureHelper::extract($media);
        $seasonLabel      = $this->normaliseSeason($calendarFeatures['season']);

        if ($seasonLabel !== null) {
            return $seasonLabel;
        }

        return match (true) {
            $month >= 3 && $month <= 5  => 'Frühling',
            $month >= 6 && $month <= 8  => 'Sommer',
            $month >= 9 && $month <= 11 => 'Herbst',
            default                     => 'Winter',
        };
    }

    private function normaliseSeason(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower($value);

        return match ($normalized) {
            'winter' => 'Winter',
            'spring', 'frühling' => 'Frühling',
            'summer', 'sommer' => 'Sommer',
            'autumn', 'fall', 'herbst' => 'Herbst',
            default => null,
        };
    }
}
