<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Picks the best weekend getaway (1..3 nights) per year and aggregates them into one over-years memory.
 */
final class WeekendGetawaysOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minNights = 1,
        private readonly int $maxNights = 3,
        private readonly int $minItemsPerDay = 4,
        private readonly int $minYears = 3,
        private readonly int $minItemsTotal = 24
    ) {
        if ($this->minNights < 1) {
            throw new \InvalidArgumentException('minNights must be >= 1.');
        }
        if ($this->maxNights < 1) {
            throw new \InvalidArgumentException('maxNights must be >= 1.');
        }
        if ($this->maxNights < $this->minNights) {
            throw new \InvalidArgumentException('maxNights must be >= minNights.');
        }
        if ($this->minItemsPerDay < 1) {
            throw new \InvalidArgumentException('minItemsPerDay must be >= 1.');
        }
        if ($this->minYears < 1) {
            throw new \InvalidArgumentException('minYears must be >= 1.');
        }
        if ($this->minItemsTotal < 1) {
            throw new \InvalidArgumentException('minItemsTotal must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'weekend_getaways_over_years';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<int, array<string, list<Media>>> $byYearDay */
        $byYearDay = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($tz);
            $y = (int) $local->format('Y');
            $d = $local->format('Y-m-d');
            $byYearDay[$y] ??= [];
            $byYearDay[$y][$d] ??= [];
            $byYearDay[$y][$d][] = $m;
        }

        /** @var list<Media> $membersAllYears */
        $membersAllYears = [];
        /** @var array<int,bool> $yearsPicked */
        $yearsPicked = [];

        foreach ($byYearDay as $year => $daysMap) {
            // sort days
            $days = \array_keys($daysMap);
            \sort($days, \SORT_STRING);

            // pack days into consecutive runs
            /** @var list<array{days:list<string>, items:list<Media>}> $runs */
            $runs = [];
            /** @var list<string> $runDays */
            $runDays = [];
            /** @var list<Media> $runItems */
            $runItems = [];
            $prev = null;

            $flush = function () use (&$runs, &$runDays, &$runItems): void {
                if (\count($runDays) > 0) {
                    $runs[] = ['days' => $runDays, 'items' => $runItems];
                }
                $runDays = [];
                $runItems = [];
            };

            foreach ($days as $d) {
                if ($prev !== null && !$this->isNextDay($prev, $d)) {
                    $flush();
                }
                $runDays[] = $d;
                foreach ($daysMap[$d] as $m) {
                    $runItems[] = $m;
                }
                $prev = $d;
            }
            $flush();

            // evaluate runs: keep only those that (a) include a weekend, (b) 1..3 nights, (c) have enough items per day
            /** @var list<array{days:list<string>, items:list<Media>}> $candidates */
            $candidates = [];

            foreach ($runs as $r) {
                $nDays = \count($r['days']);
                if ($nDays < 2) {
                    continue;
                }
                $nights = $nDays - 1;
                if ($nights < $this->minNights || $nights > $this->maxNights) {
                    continue;
                }
                // must include weekend day (Sat/Sun)
                if (!$this->containsWeekendDay($r['days'])) {
                    continue;
                }
                // each day must have enough items
                $ok = true;
                foreach ($r['days'] as $d) {
                    if (\count($daysMap[$d]) < $this->minItemsPerDay) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
                $candidates[] = $r;
            }

            if ($candidates === []) {
                continue;
            }

            // pick best candidate: by items count (desc), tie-breaker by span (more nights), then by latest
            \usort($candidates, function (array $a, array $b): int {
                $na = \count($a['items']);
                $nb = \count($b['items']);
                if ($na !== $nb) {
                    return $na < $nb ? 1 : -1;
                }
                $sa = \count($a['days']);
                $sb = \count($b['days']);
                if ($sa !== $sb) {
                    return $sa < $sb ? 1 : -1;
                }
                return \strcmp($a['days'][0], $b['days'][0]); // earlier first
            });

            $best = $candidates[0];
            foreach ($best['items'] as $m) {
                $membersAllYears[] = $m;
            }
            $yearsPicked[$year] = true;
        }

        if (\count($yearsPicked) < $this->minYears || \count($membersAllYears) < $this->minItemsTotal) {
            return [];
        }

        \usort($membersAllYears, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $centroid = MediaMath::centroid($membersAllYears);
        $time     = MediaMath::timeRange($membersAllYears);

        return [
            new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'years'      => \array_values(\array_keys($yearsPicked)),
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $membersAllYears)
            ),
        ];
    }

    /**
     * @param list<string> $days
     */
    private function containsWeekendDay(array $days): bool
    {
        foreach ($days as $d) {
            $ts = \strtotime($d . ' 12:00:00');
            if ($ts === false) {
                continue;
            }
            $dow = (int) \gmdate('N', $ts); // 1..7
            if ($dow === 6 || $dow === 7) {
                return true;
            }
        }
        return false;
    }
}
