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
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;

use function array_keys;
use function array_values;
use function assert;
use function count;
use function usort;

/**
 * Aggregates all items from the current month across different years.
 */
final readonly class ThisMonthOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;

    public function __construct(
        private string $timezone = 'Europe/Berlin',
        private int $minYears = 3,
        private int $minItemsTotal = 24,
        private int $minDistinctDays = 8,
    ) {
        if ($this->minYears < 1) {
            throw new InvalidArgumentException('minYears must be >= 1.');
        }

        if ($this->minItemsTotal < 1) {
            throw new InvalidArgumentException('minItemsTotal must be >= 1.');
        }

        if ($this->minDistinctDays < 1) {
            throw new InvalidArgumentException('minDistinctDays must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'this_month_over_years';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz  = new DateTimeZone($this->timezone);
        $now = new DateTimeImmutable('now', $tz);
        $mon = (int) $now->format('n');

        /** @var array<int, true> $years */
        $years = [];
        /** @var array<string, true> $days */
        $days = [];

        /** @var list<Media> $picked */
        $picked = $this->filterTimestampedItemsBy(
            $items,
            static function (Media $m) use ($tz, $mon, &$years, &$days): bool {
                $takenAt = $m->getTakenAt();
                assert($takenAt instanceof DateTimeImmutable);

                $local = $takenAt->setTimezone($tz);
                if ((int) $local->format('n') !== $mon) {
                    return false;
                }

                $years[(int) $local->format('Y')] = true;
                $days[$local->format('Y-m-d')]    = true;

                return true;
            }
        );

        if (count($picked) < $this->minItemsTotal || count($years) < $this->minYears || count($days) < $this->minDistinctDays) {
            return [];
        }

        usort($picked, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $centroid = $this->computeCentroid($picked);
        $time     = $this->computeTimeRange($picked);

        $params = [
            'month'      => $mon,
            'years'      => array_values(array_keys($years)),
            'time_range' => $time,
        ];

        $tags = $this->collectDominantTags($picked);
        if ($tags !== []) {
            $params = [...$params, ...$tags];
        }

        return [
            new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: $this->toMemberIds($picked)
            ),
        ];
    }
}
