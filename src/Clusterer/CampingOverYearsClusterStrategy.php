<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Picks the best multi-day camping run per year and aggregates over years.
 */
final class CampingOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;
    use MediaFilterTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItemsPerDay = 3,
        private readonly int $minNights = 2,
        private readonly int $maxNights = 14,
        private readonly int $minYears = 3,
        private readonly int $minItemsTotal = 24
    ) {
        if ($this->minItemsPerDay < 1) {
            throw new \InvalidArgumentException('minItemsPerDay must be >= 1.');
        }
        if ($this->minNights < 1) {
            throw new \InvalidArgumentException('minNights must be >= 1.');
        }
        if ($this->maxNights < 1) {
            throw new \InvalidArgumentException('maxNights must be >= 1.');
        }
        if ($this->maxNights < $this->minNights) {
            throw new \InvalidArgumentException('maxNights must be >= minNights.');
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
        return 'camping_over_years';
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

        $campingItems = $this->filterTimestampedItemsBy(
            $items,
            fn (Media $m): bool => $this->looksCamping(\strtolower($m->getPath()))
        );

        foreach ($campingItems as $m) {
            $t = $m->getTakenAt();
            \assert($t instanceof DateTimeImmutable);

            $y = (int) $t->format('Y');
            $d = $t->setTimezone($tz)->format('Y-m-d');
            $byYearDay[$y] ??= [];
            $byYearDay[$y][$d] ??= [];
            $byYearDay[$y][$d][] = $m;
        }

        /** @var list<Media> $picked */
        $picked = [];
        /** @var array<int,bool> $years */
        $years = [];

        foreach ($byYearDay as $year => $daysMap) {
            $eligibleDaysMap = $this->filterGroupsByMinItems($daysMap, $this->minItemsPerDay);

            if ($eligibleDaysMap === []) {
                continue;
            }

            $days = \array_keys($eligibleDaysMap);
            \sort($days, \SORT_STRING);

            /** @var list<array{days:list<string>, items:list<Media>}> $runs */
            $runs = [];
            $runDays = [];
            $runItems = [];
            $prev = null;

            $flushRun = function () use (&$runs, &$runDays, &$runItems): void {
                if (\count($runDays) > 0) {
                    $runs[] = ['days' => $runDays, 'items' => $runItems];
                }
                $runDays = [];
                $runItems = [];
            };

            foreach ($days as $d) {
                if ($prev !== null && !$this->isNextDay($prev, $d)) {
                    $flushRun();
                }
                $runDays[] = $d;
                foreach ($eligibleDaysMap[$d] as $m) {
                    $runItems[] = $m;
                }
                $prev = $d;
            }
            $flushRun();

            /** filter by nights and pick best */
            $candidates = [];
            foreach ($runs as $r) {
                $nights = \count($r['days']) - 1;
                if ($nights < $this->minNights || $nights > $this->maxNights) {
                    continue;
                }
                $candidates[] = $r;
            }

            if ($candidates === []) {
                continue;
            }

            \usort($candidates, static function (array $a, array $b): int {
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
                return \strcmp($a['days'][0], $b['days'][0]);
            });

            $best = $candidates[0];
            foreach ($best['items'] as $m) {
                $picked[] = $m;
            }
            $years[$year] = true;
        }

        if (\count($years) < $this->minYears || \count($picked) < $this->minItemsTotal) {
            return [];
        }

        \usort($picked, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
        $centroid = MediaMath::centroid($picked);
        $time     = MediaMath::timeRange($picked);

        return [
            new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'years'      => \array_values(\array_keys($years)),
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $picked)
            ),
        ];
    }

    private function looksCamping(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = ['camping', 'zelt', 'zelten', 'wohnmobil', 'caravan', 'wohnwagen', 'campground', 'camp site', 'campsite', 'stellplatz'];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
