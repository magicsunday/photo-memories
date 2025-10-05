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

use function assert;
use MagicSunday\Memories\Utility\MediaMath;

use function array_keys;
use function array_map;
use function assert;
use function count;
use function floor;
use function max;
use function sort;
use function sprintf;
use function usort;

use const SORT_STRING;

/**
 * Detects the earliest visit session per geogrid cell (first time at this place).
 */
final readonly class FirstVisitPlaceClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;
    use MediaFilterTrait;

    public function __construct(
        private LocationHelper $locHelper,
        private float $gridDegrees = 0.01, // ~1.1 km in lat
        private string $timezone = 'Europe/Berlin',
        private int $minItemsPerDay = 4,
        private int $minNights = 0,  // 0..3 (0 means single day ok)
        private int $maxNights = 3,
        private int $minItemsTotal = 8,
    ) {
        if ($this->gridDegrees <= 0.0) {
            throw new InvalidArgumentException('gridDegrees must be > 0.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        if ($this->minNights < 0) {
            throw new InvalidArgumentException('minNights must be >= 0.');
        }

        if ($this->maxNights < 0) {
            throw new InvalidArgumentException('maxNights must be >= 0.');
        }

        if ($this->maxNights < $this->minNights) {
            throw new InvalidArgumentException('maxNights must be >= minNights.');
        }

        if ($this->minItemsTotal < 1) {
            throw new InvalidArgumentException('minItemsTotal must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'first_visit_place';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     * @throws \DateInvalidTimeZoneException
     * @throws \DateMalformedStringException
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var list<Media> $timestampedGps */
        $timestampedGps = $this->filterTimestampedGpsItems($items);

        /** @var array<string, array<string, list<Media>>> $cellDayMap */
        $cellDayMap = [];

        foreach ($timestampedGps as $m) {
            $t = $m->getTakenAt();
            assert($t instanceof DateTimeImmutable);
            $lat   = $m->getGpsLat();
            $lon   = $m->getGpsLon();
            assert($lat !== null && $lon !== null);
            $local = $t->setTimezone($tz);
            $day   = $local->format('Y-m-d');
            $cell  = $this->cellKey($lat, $lon);
            $cellDayMap[$cell] ??= [];
            $cellDayMap[$cell][$day] ??= [];
            $cellDayMap[$cell][$day][] = $m;
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        /** @var array<string, array{index: int, members: int, duration: int}> $seenPlaceDay */
        $seenPlaceDay = [];

        foreach ($cellDayMap as $cell => $daysMap) {
            $eligibleDaysMap = $this->filterGroupsByMinItems($daysMap, $this->minItemsPerDay);

            if ($eligibleDaysMap === []) {
                continue;
            }

            $days = array_keys($eligibleDaysMap);
            sort($days, SORT_STRING);

            // find earliest run satisfying constraints
            /** @var list<string> $runDays */
            $runDays = [];
            $prev    = null;

            $flush = function () use (&$runDays, &$out, $eligibleDaysMap, $cell, &$seenPlaceDay, $tz): void {
                if ($runDays === []) {
                    return;
                }

                /** @var list<Media> $members */
                $members = [];
                foreach ($runDays as $d) {
                    foreach ($eligibleDaysMap[$d] as $m) {
                        $members[] = $m;
                    }
                }

                $memberCount = count($members);
                if ($memberCount < $this->minItemsTotal) {
                    $runDays = [];

                    return;
                }

                usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

                $centroid = MediaMath::centroid($members);
                $time     = MediaMath::timeRange($members);
                $dayLocal = (new DateTimeImmutable('@' . $time['from']))
                    ->setTimezone($tz)
                    ->format('Y-m-d');
                $duration = max(0, (int) ($time['to'] - $time['from']));

                $params = [
                    'grid_cell'  => $cell,
                    'time_range' => $time,
                ];

                $label = $this->locHelper->majorityLabel($members);

                if ($label !== null) {
                    $params['place'] = $label;
                }

                $draft = new ClusterDraft(
                    algorithm: 'first_visit_place',
                    params: $params,
                    centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                    members: array_map(static fn (Media $m): int => $m->getId(), $members)
                );

                if ($label !== null) {
                    $key = sprintf('%s|%s', $label, $dayLocal);

                    if (isset($seenPlaceDay[$key])) {
                        $existing         = $seenPlaceDay[$key];
                        $existingMembers  = $existing['members'];
                        $existingDuration = $existing['duration'];

                        $shouldReplace = false;

                        if ($memberCount > $existingMembers) {
                            $shouldReplace = true;
                        } elseif ($memberCount === $existingMembers && $duration < $existingDuration) {
                            $shouldReplace = true;
                        }

                        if ($shouldReplace) {
                            $out[$existing['index']] = $draft;
                            $seenPlaceDay[$key]      = [
                                'index'    => $existing['index'],
                                'members'  => $memberCount,
                                'duration' => $duration,
                            ];
                        }
                    } else {
                        $seenPlaceDay[$key] = [
                            'index'    => count($out),
                            'members'  => $memberCount,
                            'duration' => $duration,
                        ];
                        $out[] = $draft;
                    }
                } else {
                    $out[] = $draft;
                }

                $runDays = [];
            };

            /** iterate and pick the first qualifying run */
            $haveFirst = false;
            foreach ($days as $d) {
                // consecutive day logic
                if ($prev !== null && !$this->isNextDay($prev, $d)) {
                    // check previous run
                    $nights = max(0, count($runDays) - 1);
                    if ($nights >= $this->minNights && $nights <= $this->maxNights) {
                        $flush();
                        $haveFirst = true;
                        break; // only earliest session per cell
                    }

                    $runDays = [];
                }

                $runDays[] = $d;
                $prev      = $d;
            }

            if ($haveFirst === false && $runDays !== []) {
                $nights = max(0, count($runDays) - 1);
                if ($nights >= $this->minNights && $nights <= $this->maxNights) {
                    $flush();
                }
            }
        }

        return $out;
    }

    private function cellKey(float $lat, float $lon): string
    {
        $gd   = $this->gridDegrees;
        $rlat = $gd * floor($lat / $gd);
        $rlon = $gd * floor($lon / $gd);

        return sprintf('%.4f,%.4f', $rlat, $rlon);
    }
}
