<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Detects the earliest visit session per geogrid cell (first time at this place).
 */
final class FirstVisitPlaceClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;

    public function __construct(
        private readonly LocationHelper $locHelper,
        private readonly float $gridDegrees = 0.01, // ~1.1 km in lat
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItemsPerDay = 4,
        private readonly int $minNights = 0,  // 0..3 (0 means single day ok)
        private readonly int $maxNights = 3,
        private readonly int $minItemsTotal = 8
    ) {
        if ($this->gridDegrees <= 0.0) {
            throw new \InvalidArgumentException('gridDegrees must be > 0.');
        }
        if ($this->minItemsPerDay < 1) {
            throw new \InvalidArgumentException('minItemsPerDay must be >= 1.');
        }
        if ($this->minNights < 0) {
            throw new \InvalidArgumentException('minNights must be >= 0.');
        }
        if ($this->maxNights < 0) {
            throw new \InvalidArgumentException('maxNights must be >= 0.');
        }
        if ($this->maxNights < $this->minNights) {
            throw new \InvalidArgumentException('maxNights must be >= minNights.');
        }
        if ($this->minItemsTotal < 1) {
            throw new \InvalidArgumentException('minItemsTotal must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'first_visit_place';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<string, array<string, list<Media>>> $cellDayMap */
        $cellDayMap = [];

        foreach ($items as $m) {
            $t   = $m->getTakenAt();
            $lat = $m->getGpsLat();
            $lon = $m->getGpsLon();
            if (!$t instanceof DateTimeImmutable || $lat === null || $lon === null) {
                continue;
            }
            $local = $t->setTimezone($tz);
            $day = $local->format('Y-m-d');
            $cell = $this->cellKey((float)$lat, (float)$lon);
            $cellDayMap[$cell] ??= [];
            $cellDayMap[$cell][$day] ??= [];
            $cellDayMap[$cell][$day][] = $m;
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($cellDayMap as $cell => $daysMap) {
            $eligibleDaysMap = \array_filter(
                $daysMap,
                fn (array $list): bool => \count($list) >= $this->minItemsPerDay
            );

            if ($eligibleDaysMap === []) {
                continue;
            }

            $days = \array_keys($eligibleDaysMap);
            \sort($days, \SORT_STRING);

            // find earliest run satisfying constraints
            /** @var list<string> $runDays */
            $runDays = [];
            $prev = null;

            $flush = function () use (&$runDays, &$out, $eligibleDaysMap, $cell): void {
                if (\count($runDays) === 0) {
                    return;
                }
                /** @var list<Media> $members */
                $members = [];
                foreach ($runDays as $d) {
                    foreach ($eligibleDaysMap[$d] as $m) {
                        $members[] = $m;
                    }
                }
                if (\count($members) < $this->minItemsTotal) {
                    $runDays = [];
                    return;
                }
                \usort($members, static fn(Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

                $centroid = MediaMath::centroid($members);
                $time     = MediaMath::timeRange($members);

                $params = [
                    'grid_cell'  => $cell,
                    'time_range' => $time,
                ];

                $label = $this->locHelper->majorityLabel($members);

                if ($label !== null) {
                    $params['place'] = $label;
                }

                $out[] = new ClusterDraft(
                    algorithm: 'first_visit_place',
                    params: $params,
                    centroid: ['lat' => (float)$centroid['lat'], 'lon' => (float)$centroid['lon']],
                    members: \array_map(static fn(Media $m): int => $m->getId(), $members)
                );

                $runDays = [];
            };

            /** iterate and pick the first qualifying run */
            $haveFirst = false;
            foreach ($days as $d) {
                // consecutive day logic
                if ($prev !== null && !$this->isNextDay($prev, $d)) {
                    // check previous run
                    $nights = \max(0, \count($runDays) - 1);
                    if ($nights >= $this->minNights && $nights <= $this->maxNights) {
                        $flush();
                        $haveFirst = true;
                        break; // only earliest session per cell
                    }
                    $runDays = [];
                }

                $runDays[] = $d;
                $prev = $d;
            }

            if ($haveFirst === false && \count($runDays) > 0) {
                $nights = \max(0, \count($runDays) - 1);
                if ($nights >= $this->minNights && $nights <= $this->maxNights) {
                    $flush();
                }
            }
        }

        return $out;
    }

    private function cellKey(float $lat, float $lon): string
    {
        $gd = $this->gridDegrees;
        $rlat = $gd * \floor($lat / $gd);
        $rlon = $gd * \floor($lon / $gd);
        return \sprintf('%.4f,%.4f', $rlat, $rlon);
    }
}
