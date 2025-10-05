<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
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
use function is_int;
use function sprintf;
use function usort;

/**
 * Collects all photos taken around today's month/day across different years.
 * Example: Feb-14 across 2014..2025 within a +/- window of days.
 */
final readonly class OnThisDayOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;

    public function __construct(
        private string $timezone = 'Europe/Berlin',
        private int $windowDays = 0,   // 0 = exact same month/day, 1..3 = tolerant
        private int $minYears = 3,
        private int $minItemsTotal = 12,
    ) {
        if ($this->windowDays < 0) {
            throw new InvalidArgumentException('windowDays must be >= 0.');
        }

        if ($this->minYears < 1) {
            throw new InvalidArgumentException('minYears must be >= 1.');
        }

        if ($this->minItemsTotal < 1) {
            throw new InvalidArgumentException('minItemsTotal must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'on_this_day_over_years';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     *
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     */
    public function cluster(array $items): array
    {
        $now         = new DateTimeImmutable('now', new DateTimeZone($this->timezone));
        $anchorMonth = (int) $now->format('n');
        $anchorDay   = (int) $now->format('j');

        /** @var array<int, true> $years */
        $years = [];
        $tz    = $now->getTimezone();

        /** @var list<Media> $picked */
        $picked = $this->filterTimestampedItemsBy(
            $items,
            function (Media $m) use ($tz, $anchorMonth, $anchorDay, &$years): bool {
                $takenAt = $m->getTakenAt();
                assert($takenAt instanceof DateTimeImmutable);

                $local = $takenAt->setTimezone($tz);
                $month = (int) $local->format('n');
                $day   = (int) $local->format('j');

                if ($this->monthDayDistance($anchorMonth, $anchorDay, $month, $day) > $this->windowDays) {
                    return false;
                }

                $years[(int) $local->format('Y')] = true;

                return true;
            }
        );

        if (count($picked) < $this->minItemsTotal || count($years) < $this->minYears) {
            return [];
        }

        usort($picked, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $centroid = $this->computeCentroid($picked);
        $time     = $this->computeTimeRange($picked);

        $params = [
            'time_range' => $time,
            'years'      => array_values(array_keys($years)),
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

    private function monthDayDistance(int $m1, int $d1, int $m2, int $d2): int
    {
        // Simple absolute distance in days ignoring leap-year wrap; good enough for small windows.
        $tz   = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('2001-%02d-%02d', $m1, $d1), $tz);
        $ref  = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('2001-%02d-%02d', $m2, $d2), $tz);

        if (!$date instanceof DateTimeImmutable || !$ref instanceof DateTimeImmutable) {
            return 9999;
        }

        $interval = $date->diff($ref);
        $days     = $interval->days;

        if (!is_int($days)) {
            return 9999;
        }

        return $days;
    }
}
