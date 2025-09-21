<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks the best multi-day winter snow vacation per year and aggregates over years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 63])]
final class SnowVacationOverYearsClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItemsPerDay = 4,
        private readonly int $minNights = 3,
        private readonly int $maxNights = 14,
        private readonly int $minYears = 3,
        private readonly int $minItemsTotal = 30
    ) {
        if ($this->maxNights < $this->minNights) {
            throw new \InvalidArgumentException('maxNights must be >= minNights.');
        }
    }

    public function name(): string
    {
        return 'snow_vacation_over_years';
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
            $path = \strtolower($m->getPath());
            if (!$t instanceof DateTimeImmutable || !$this->looksSnow($path)) {
                continue;
            }
            $mon = (int) $t->setTimezone($tz)->format('n');
            $winter = ($mon === 12 || $mon <= 2);
            if ($winter === false) {
                continue;
            }
            $y = (int) $t->format('Y');
            $d = $t->setTimezone($tz)->format('Y-m-d');
            $byYearDay[$y] ??= [];
            $byYearDay[$y][$d] ??= [];
            $byYearDay[$y][$d][] = $m;
        }

        /** @var list<Media> $membersAllYears */
        $membersAllYears = [];
        /** @var array<int,bool> $yearsPicked */
        $yearsPicked = [];

        foreach ($byYearDay as $year => $daysMap) {
            $days = \array_keys($daysMap);
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
                foreach ($daysMap[$d] as $m) {
                    $runItems[] = $m;
                }
                $prev = $d;
            }
            $flushRun();

            /** filter runs by nights range & per-day min items */
            $candidates = [];
            foreach ($runs as $r) {
                $nights = \count($r['days']) - 1;
                if ($nights < $this->minNights || $nights > $this->maxNights) {
                    continue;
                }
                $ok = true;
                foreach ($r['days'] as $d) {
                    if (\count($daysMap[$d]) < $this->minItemsPerDay) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $candidates[] = $r;
                }
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

    private function isNextDay(string $a, string $b): bool
    {
        $ta = \strtotime($a . ' 00:00:00');
        $tb = \strtotime($b . ' 00:00:00');
        if ($ta === false || $tb === false) {
            return false;
        }
        return ($tb - $ta) === 86400;
    }

    private function looksSnow(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = ['schnee', 'snow', 'ski', 'langlauf', 'skitour', 'snowboard', 'piste', 'gondel', 'lift', 'alpen', 'hütte', 'huette'];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
