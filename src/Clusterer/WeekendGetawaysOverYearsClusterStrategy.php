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
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_key_first;
use function array_keys;
use function array_map;
use function array_values;
use function assert;
use function arsort;
use function count;
use function gmdate;
use function array_search;
use function sort;
use function strcmp;
use function strtotime;
use function usort;

use const SORT_STRING;
use const SORT_NUMERIC;

/**
 * Picks the best weekend getaway (1..3 nights) per year and aggregates them into one over-years memory.
 */
final readonly class WeekendGetawaysOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;
    use MediaFilterTrait;

    public function __construct(
        private LocationHelper $locHelper,
        private string $timezone = 'Europe/Berlin',
        private int $minNights = 1,
        private int $maxNights = 3,
        private int $minItemsPerDay = 4,
        private int $minYears = 3,
        private int $minItemsTotal = 24,
        #[Autowire(env: 'MEMORIES_HOME_LAT')]
        private ?float $homeLat = null,
        #[Autowire(env: 'MEMORIES_HOME_LON')]
        private ?float $homeLon = null,
        private float $minAwayKm = 80.0,
    ) {
        if ($this->minNights < 1) {
            throw new InvalidArgumentException('minNights must be >= 1.');
        }

        if ($this->maxNights < 1) {
            throw new InvalidArgumentException('maxNights must be >= 1.');
        }

        if ($this->maxNights < $this->minNights) {
            throw new InvalidArgumentException('maxNights must be >= minNights.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        if ($this->minYears < 1) {
            throw new InvalidArgumentException('minYears must be >= 1.');
        }

        if ($this->minItemsTotal < 1) {
            throw new InvalidArgumentException('minItemsTotal must be >= 1.');
        }

        if ($this->minAwayKm <= 0.0) {
            throw new InvalidArgumentException('minAwayKm must be > 0.');
        }
    }

    public function name(): string
    {
        return 'weekend_getaways_over_years';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<int, array<string, list<Media>>> $byYearDay */
        $byYearDay = [];

        foreach ($timestamped as $m) {
            $t = $m->getTakenAt();
            assert($t instanceof DateTimeImmutable);
            $local = $t->setTimezone($tz);
            $y     = (int) $local->format('Y');
            $d     = $local->format('Y-m-d');
            $byYearDay[$y] ??= [];
            $byYearDay[$y][$d] ??= [];
            $byYearDay[$y][$d][] = $m;
        }

        /** @var list<Media> $membersAllYears */
        $membersAllYears = [];
        /** @var array<int,bool> $yearsPicked */
        $yearsPicked = [];

        foreach ($byYearDay as $year => $daysMap) {
            $eligibleDaysMap = $this->filterGroupsByMinItems($daysMap, $this->minItemsPerDay);

            if ($eligibleDaysMap === []) {
                continue;
            }

            $allDays = array_keys($daysMap);
            sort($allDays, SORT_STRING);

            $dayLocality = $this->buildDayLocalityMap($daysMap);

            // sort days
            $days = array_keys($eligibleDaysMap);
            sort($days, SORT_STRING);

            // pack days into consecutive runs
            /** @var list<array{days:list<string>, items:list<Media>}> $runs */
            $runs = [];
            /** @var list<string> $runDays */
            $runDays = [];
            /** @var list<Media> $runItems */
            $runItems = [];
            $prev     = null;

            $flush = function () use (&$runs, &$runDays, &$runItems): void {
                if ($runDays !== []) {
                    $runs[] = ['days' => $runDays, 'items' => $runItems];
                }

                $runDays  = [];
                $runItems = [];
            };

            foreach ($days as $d) {
                if ($prev !== null && !$this->isNextDay($prev, $d)) {
                    $flush();
                }

                $runDays[] = $d;
                foreach ($eligibleDaysMap[$d] as $m) {
                    $runItems[] = $m;
                }

                $prev = $d;
            }

            $flush();

            // evaluate runs: keep only those that (a) include a weekend and (b) span 1..3 nights
            /** @var list<array{days:list<string>, items:list<Media>}> $candidates */
            $candidates = [];

            foreach ($runs as $r) {
                $nDays = count($r['days']);
                if ($nDays < 2) {
                    continue;
                }

                $nights = $nDays - 1;
                if ($nights < $this->minNights) {
                    continue;
                }

                if ($nights > $this->maxNights) {
                    continue;
                }

                // must include weekend day (Sat/Sun)
                if (!$this->containsWeekendDay($r['days'])) {
                    continue;
                }

                if (!$this->isRunBracketedByDifferentLocality($r, $allDays, $dayLocality)) {
                    continue;
                }

                if (!$this->isRunFarEnoughFromHome($r['items'])) {
                    continue;
                }

                $candidates[] = $r;
            }

            if ($candidates === []) {
                continue;
            }

            // pick best candidate: by items count (desc), tie-breaker by span (more nights), then by latest
            usort($candidates, function (array $a, array $b): int {
                $na = count($a['items']);
                $nb = count($b['items']);
                if ($na !== $nb) {
                    return $na < $nb ? 1 : -1;
                }

                $sa = count($a['days']);
                $sb = count($b['days']);
                if ($sa !== $sb) {
                    return $sa < $sb ? 1 : -1;
                }

                return strcmp($a['days'][0], $b['days'][0]); // earlier first
            });

            $best = $candidates[0];
            foreach ($best['items'] as $m) {
                $membersAllYears[] = $m;
            }

            $yearsPicked[$year] = true;
        }

        if (count($yearsPicked) < $this->minYears || count($membersAllYears) < $this->minItemsTotal) {
            return [];
        }

        usort($membersAllYears, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $centroid = MediaMath::centroid($membersAllYears);
        $time     = MediaMath::timeRange($membersAllYears);

        return [
            new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'years'      => array_values(array_keys($yearsPicked)),
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $membersAllYears)
            ),
        ];
    }

    /**
     * @param list<string> $days
     */
    private function containsWeekendDay(array $days): bool
    {
        foreach ($days as $d) {
            $ts = strtotime($d . ' 12:00:00');
            if ($ts === false) {
                continue;
            }

            $dow = (int) gmdate('N', $ts); // 1..7
            if ($dow === 6 || $dow === 7) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<Media>> $daysMap
     *
     * @return array<string, ?string>
     */
    private function buildDayLocalityMap(array $daysMap): array
    {
        $locality = [];

        foreach ($daysMap as $day => $items) {
            $locality[$day] = $this->majorityLocalityKey($items);
        }

        return $locality;
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     * @param list<string>                                $sortedDays
     * @param array<string, ?string>                      $dayLocality
     */
    private function isRunBracketedByDifferentLocality(array $run, array $sortedDays, array $dayLocality): bool
    {
        $runDays = $run['days'];
        if (count($runDays) === 0) {
            return false;
        }

        $runLocality = $this->majorityLocalityKey($run['items']);
        if ($runLocality === null) {
            return false;
        }

        $firstDay = $runDays[0];
        $lastDayIndex = count($runDays) - 1;
        $lastDay = $runDays[$lastDayIndex];

        $prevDay = $this->previousDayKey($sortedDays, $firstDay);
        $nextDay = $this->nextDayKey($sortedDays, $lastDay);

        if ($prevDay === null || $nextDay === null) {
            return false;
        }

        $prevLocality = $dayLocality[$prevDay] ?? null;
        $nextLocality = $dayLocality[$nextDay] ?? null;

        if ($prevLocality === null || $nextLocality === null) {
            return false;
        }

        if ($prevLocality === $runLocality) {
            return false;
        }

        if ($nextLocality === $runLocality) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $sortedDays
     */
    private function previousDayKey(array $sortedDays, string $reference): ?string
    {
        $index = array_search($reference, $sortedDays, true);
        if ($index === false || $index === 0) {
            return null;
        }

        return $sortedDays[$index - 1];
    }

    /**
     * @param list<string> $sortedDays
     */
    private function nextDayKey(array $sortedDays, string $reference): ?string
    {
        $index = array_search($reference, $sortedDays, true);
        if ($index === false) {
            return null;
        }

        $nextIndex = $index + 1;
        $total = count($sortedDays);
        if ($nextIndex >= $total) {
            return null;
        }

        return $sortedDays[$nextIndex];
    }

    /**
     * @param list<Media> $items
     */
    private function majorityLocalityKey(array $items): ?string
    {
        /** @var array<string,int> $counts */
        $counts = [];

        foreach ($items as $media) {
            $key = $this->locHelper->localityKeyForMedia($media);
            if ($key === null) {
                continue;
            }

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts, SORT_NUMERIC);

        $firstKey = array_key_first($counts);

        return $firstKey instanceof string ? $firstKey : null;
    }

    /**
     * @param list<Media> $items
     */
    private function isRunFarEnoughFromHome(array $items): bool
    {
        $distanceKm = $this->distanceFromHomeKm($items);

        if ($distanceKm === null) {
            return true;
        }

        return $distanceKm >= $this->minAwayKm;
    }

    /**
     * @param list<Media> $items
     */
    private function distanceFromHomeKm(array $items): ?float
    {
        if ($this->homeLat === null || $this->homeLon === null) {
            return null;
        }

        foreach ($items as $media) {
            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();

            if ($lat !== null && $lon !== null) {
                $meters = MediaMath::haversineDistanceInMeters(
                    $this->homeLat,
                    $this->homeLon,
                    (float) $lat,
                    (float) $lon
                );

                return $meters / 1000.0;
            }
        }

        return null;
    }
}
