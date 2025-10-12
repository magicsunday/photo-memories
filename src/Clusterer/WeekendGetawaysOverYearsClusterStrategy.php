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
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\CalendarFeatureHelper;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function array_any;
use function array_filter;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_search;
use function array_values;
use function arsort;
use function assert;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function floor;
use function sort;
use function strcmp;
use function strtolower;
use function usort;

use const SORT_NUMERIC;
use const SORT_STRING;

/**
 * Picks the best weekend getaway (1..3 nights) per year and aggregates them into one over-years memory.
 */
final readonly class WeekendGetawaysOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;
    use MediaFilterTrait;

    private LocationHelper $locHelper;

    private float $minAwayDistanceKm;

    private float $maxAwayDistanceKm;

    public function __construct(
        ?LocationHelper $locHelper = null,
        private string $timezone = 'Europe/Berlin',
        private int $minNights = 2,
        private int $maxNights = 3,
        private int $minItemsPerDay = 4,
        private int $minYears = 3,
        private int $minItemsTotal = 24,
        float $minAwayDistanceKm = 80.0,
        float $maxAwayDistanceKm = 400.0,
    ) {
        $this->locHelper = $locHelper ?? LocationHelper::createDefault();
        $this->minAwayDistanceKm = $minAwayDistanceKm;
        $this->maxAwayDistanceKm = $maxAwayDistanceKm;
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

        if ($this->minAwayDistanceKm <= 0.0) {
            throw new InvalidArgumentException('minAwayDistanceKm must be greater than 0.');
        }

        if ($this->maxAwayDistanceKm <= 0.0) {
            throw new InvalidArgumentException('maxAwayDistanceKm must be greater than 0.');
        }

        if ($this->maxAwayDistanceKm <= $this->minAwayDistanceKm) {
            throw new InvalidArgumentException('maxAwayDistanceKm must be greater than minAwayDistanceKm.');
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
     *
     * @throws DateInvalidTimeZoneException
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

            $flush = static function () use (&$runs, &$runDays, &$runItems): void {
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
            $candidates = array_values(array_filter(
                $runs,
                function (array $run) use ($allDays, $dayLocality): bool {
                    $nDays = count($run['days']);
                    if ($nDays < 2) {
                        return false;
                    }

                    $nights = $nDays - 1;
                    if ($nights < $this->minNights) {
                        return false;
                    }

                    if ($nights > $this->maxNights) {
                        return false;
                    }

                    // must include weekend day (Sat/Sun) detected via features or fallback calendar logic
                    if (!$this->runContainsWeekend($run)) {
                        return false;
                    }

                    if (!$this->runHasWeekendAnchor($run)) {
                        return false;
                    }

                    if (!$this->runHasValidAwayDistance($run)) {
                        return false;
                    }

                    if (!$this->runHasVacationCoreTag($run)) {
                        return false;
                    }

                    if (!$this->isRunBracketedByDifferentLocality($run, $allDays, $dayLocality)) {
                        return false;
                    }

                    return true;
                }
            ));

            if ($candidates === []) {
                continue;
            }

            // pick best candidate: by items count (desc), tie-breaker by span (more nights), then by latest
            usort($candidates, static function (array $a, array $b): int {
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
     * @param array{days: list<string>, items: list<Media>} $run
     */
    private function runContainsWeekend(array $run): bool
    {
        /** @var list<Media> $items */
        $items = $run['items'];

        $hasFeatureData = false;

        $hasWeekendFeature = array_any(
            $items,
            static function (Media $media) use (&$hasFeatureData): bool {
                $calendarFeatures = CalendarFeatureHelper::extract($media);
                $isWeekend        = $calendarFeatures['isWeekend'];

                if ($isWeekend === null) {
                    return false;
                }

                $hasFeatureData = true;

                return $isWeekend;
            }
        );

        if ($hasWeekendFeature) {
            return true;
        }

        if ($hasFeatureData) {
            return false;
        }

        return $this->containsWeekendDay($run['days']);
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     */
    private function runHasWeekendAnchor(array $run): bool
    {
        $days = $run['days'];
        if ($days === []) {
            return false;
        }

        $hasWeekend = false;
        foreach ($days as $day) {
            if ($this->isWeekendIsoDate($day)) {
                $hasWeekend = true;
                break;
            }
        }

        if ($hasWeekend) {
            return true;
        }

        foreach ($run['items'] as $media) {
            $summary = $this->extractDaySummary($media);
            if ($summary === []) {
                continue;
            }

            $dayCategory = $summary['category'] ?? null;
            if (is_string($dayCategory) && strtolower($dayCategory) === 'weekend') {
                return true;
            }
        }

        return false;
    }

    private function isWeekendIsoDate(string $day): bool
    {
        $tz = new DateTimeZone('UTC');

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $day . ' 12:00:00', $tz);
        if (!$date instanceof DateTimeImmutable) {
            return false;
        }

        $dow = (int) $date->format('N');

        return $dow >= 6;
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     */
    private function runHasValidAwayDistance(array $run): bool
    {
        $samples = [];

        foreach ($run['items'] as $media) {
            $distance = $this->resolveDistanceFromHome($media);
            if ($distance === null) {
                continue;
            }

            $samples[] = $distance;
        }

        if ($samples === []) {
            return false;
        }

        sort($samples, SORT_NUMERIC);

        $median = $this->median($samples);

        if ($median === null) {
            return false;
        }

        if ($median < $this->minAwayDistanceKm) {
            return false;
        }

        if ($median > $this->maxAwayDistanceKm) {
            return false;
        }

        return true;
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     */
    private function runHasVacationCoreTag(array $run): bool
    {
        $hasCore = false;
        $scoreSamples = [];

        foreach ($run['items'] as $media) {
            $metadata = $this->extractVacationMetadata($media);
            if ($metadata['has_core_tag']) {
                $hasCore = true;
            }

            if ($metadata['core_score'] !== null) {
                $scoreSamples[] = $metadata['core_score'];
            }
        }

        if ($hasCore === false) {
            return false;
        }

        if ($scoreSamples === []) {
            return false;
        }

        return true;
    }

    /**
     * @return array{distance_from_home_km: float|null, max_speed_kmh: float|null, category: string|null}
     */
    private function extractDaySummary(Media $media): array
    {
        $bag       = $media->getFeatureBag();
        $payload   = $bag->toArray();
        $summary   = $payload['day_summary'] ?? null;
        $result    = [
            'distance_from_home_km' => null,
            'max_speed_kmh'         => null,
            'category'              => null,
        ];

        if (!is_array($summary)) {
            return $result;
        }

        $distance = $summary['distance_from_home_km'] ?? ($summary['distance_km'] ?? null);
        if (is_float($distance) || is_int($distance) || (is_string($distance) && is_numeric($distance))) {
            $result['distance_from_home_km'] = (float) $distance;
        }

        $maxSpeed = $summary['max_speed_kmh'] ?? null;
        if (is_float($maxSpeed) || is_int($maxSpeed) || (is_string($maxSpeed) && is_numeric($maxSpeed))) {
            $result['max_speed_kmh'] = (float) $maxSpeed;
        }

        $category = $summary['category'] ?? ($summary['segment'] ?? null);
        if (is_string($category) && $category !== '') {
            $result['category'] = $category;
        }

        return $result;
    }

    private function resolveDistanceFromHome(Media $media): ?float
    {
        $summary = $this->extractDaySummary($media);
        if ($summary['distance_from_home_km'] !== null) {
            return $summary['distance_from_home_km'];
        }

        $distance = $media->getDistanceKmFromHome();
        if ($distance !== null) {
            return $distance;
        }

        return null;
    }

    /**
     * @return array{has_core_tag: bool, core_score: float|null}
     */
    private function extractVacationMetadata(Media $media): array
    {
        $bag      = $media->getFeatureBag();
        $payload  = $bag->toArray();
        $vacation = $payload['vacation'] ?? null;

        $hasCore = false;
        $score   = null;

        if (is_array($vacation)) {
            $tag = $vacation['core_tag'] ?? ($vacation['category'] ?? null);
            if (is_string($tag) && strtolower($tag) === 'core') {
                $hasCore = true;
            } elseif (is_bool($tag) && $tag) {
                $hasCore = true;
            }

            $scoreValue = $vacation['core_score'] ?? ($vacation['score'] ?? null);
            if (is_float($scoreValue) || is_int($scoreValue) || (is_string($scoreValue) && is_numeric($scoreValue))) {
                $score = (float) $scoreValue;
            }
        }

        return [
            'has_core_tag' => $hasCore,
            'core_score'   => $score,
        ];
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): ?float
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }

        $middle = (int) floor($count / 2);

        if ($count % 2 !== 0) {
            return $values[$middle];
        }

        $left  = $values[$middle - 1];
        $right = $values[$middle];

        return ($left + $right) / 2.0;
    }

    /**
     * @param list<string> $days
     */
    private function containsWeekendDay(array $days): bool
    {
        $tz = new DateTimeZone('UTC');

        return array_any(
            $days,
            static function (string $day) use ($tz): bool {
                $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $day . ' 12:00:00', $tz);
                if (!$date instanceof DateTimeImmutable) {
                    return false;
                }

                $dow = (int) $date->format('N'); // 1..7

                return $dow === 6 || $dow === 7;
            }
        );
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
            return true;
        }

        $firstDay     = $runDays[0];
        $lastDayIndex = count($runDays) - 1;
        $lastDay      = $runDays[$lastDayIndex];

        $prevDay = $this->previousDayKey($sortedDays, $firstDay);
        $nextDay = $this->nextDayKey($sortedDays, $lastDay);

        if ($prevDay === null || $nextDay === null) {
            return true;
        }

        $prevLocality = $dayLocality[$prevDay] ?? null;
        $nextLocality = $dayLocality[$nextDay] ?? null;

        if ($prevLocality === null || $nextLocality === null) {
            return true;
        }

        return $prevLocality !== $runLocality && $nextLocality !== $runLocality;
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
        $total     = count($sortedDays);
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

        return is_string($firstKey) ? $firstKey : null;
    }
}
