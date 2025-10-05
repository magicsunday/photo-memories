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
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\CalendarFeatureHelper;

use function assert;
use function explode;
use function mb_strtolower;

/**
 * Groups media by meteorological seasons per year (DE).
 * Winter is Dec–Feb (December assigned to next year).
 */
final readonly class SeasonClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;

    private ClusterQualityAggregator $qualityAggregator;

    public function __construct(
        // Minimum members per (season, year) bucket.
        private int $minItemsPerSeason = 20,
        ?ClusterQualityAggregator $qualityAggregator = null,
    ) {
        if ($this->minItemsPerSeason < 1) {
            throw new InvalidArgumentException('minItemsPerSeason must be >= 1.');
        }

        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
    }

    public function name(): string
    {
        return 'season';
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
            [$season, $year] = $this->resolveSeasonAndYear($m);

            $key = $year . ':' . $season;
            $groups[$key] ??= [];
            $groups[$key][] = $m;
        }

        /** @var array<string, list<Media>> $eligibleGroups */
        $eligibleGroups = $this->filterGroupsByMinItems($groups, $this->minItemsPerSeason);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleGroups as $key => $members) {
            [$yearStr, $season] = explode(':', $key, 2);
            $yearInt            = (int) $yearStr;

            $centroid = $this->computeCentroid($members);
            $time     = $this->computeTimeRange($members);

            $params = [
                'label'      => $season,
                'year'       => $yearInt,
                'time_range' => $time,
            ];

            $qualityParams = $this->qualityAggregator->buildParams($members);
            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $tags = $this->collectDominantTags($members);
            if ($tags !== []) {
                $params = [...$params, ...$tags];
            }

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: $this->toMemberIds($members)
            );
        }

        return $out;
}

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveSeasonAndYear(Media $media): array
    {
        $takenAt = $media->getTakenAt();
        assert($takenAt instanceof DateTimeImmutable);

        $month = (int) $takenAt->format('n');
        $year  = (int) $takenAt->format('Y');

        $calendarFeatures = CalendarFeatureHelper::extract($media);
        $seasonLabel      = $this->normaliseSeason($calendarFeatures['season']);

        if ($seasonLabel !== null) {
            if ($this->isWinterSeason($calendarFeatures['season']) && $month === 12) {
                ++$year;
            }

            return [$seasonLabel, $year];
        }

        $seasonLabel = match (true) {
            $month >= 3 && $month <= 5  => 'Frühling',
            $month >= 6 && $month <= 8  => 'Sommer',
            $month >= 9 && $month <= 11 => 'Herbst',
            default                     => 'Winter',
        };

        if ($seasonLabel === 'Winter' && $month === 12) {
            ++$year;
        }

        return [$seasonLabel, $year];
    }

    private function normaliseSeason(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower($value);

        return match ($normalized) {
            'winter'    => 'Winter',
            'spring', 'frühling' => 'Frühling',
            'summer', 'sommer'   => 'Sommer',
            'autumn', 'fall', 'herbst' => 'Herbst',
            default => null,
        };
    }

    private function isWinterSeason(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return mb_strtolower($value) === 'winter';
    }
}
