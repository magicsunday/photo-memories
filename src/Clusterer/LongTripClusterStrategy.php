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
 * Detects multi-night trips away from home based on per-day centroids and distance threshold.
 */
final class LongTripClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;
    use MediaFilterTrait;

    public function __construct(
        /** Home base; if null, strategy is effectively disabled. */
        private readonly ?float $homeLat = null,
        private readonly ?float $homeLon = null,
        private readonly float $minAwayKm = 150.0,
        private readonly int $minNights = 3,
        private readonly string $timezone = 'Europe/Berlin',
        // Counts timestamped media before we enforce GPS coverage for distance checks.
        private readonly int $minItemsPerDay = 3
    ) {
        if ($this->minAwayKm <= 0.0) {
            throw new \InvalidArgumentException('minAwayKm must be > 0.');
        }
        if ($this->minNights < 1) {
            throw new \InvalidArgumentException('minNights must be >= 1.');
        }
        if ($this->minItemsPerDay < 1) {
            throw new \InvalidArgumentException('minItemsPerDay must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'long_trip';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        if ($this->homeLat === null || $this->homeLon === null) {
            return [];
        }

        $tz = new DateTimeZone($this->timezone);

        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];
        foreach ($timestamped as $m) {
            $t = $m->getTakenAt();
            \assert($t instanceof DateTimeImmutable);
            $local = $t->setTimezone($tz);
            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        $eligibleDays = $this->filterGroupsByMinItems($byDay, $this->minItemsPerDay);

        /**
         * Keep both the complete per-day members and the GPS-only subset so we can
         * enforce distance thresholds without discarding supporting media.
         *
         * @var array<string, list<Media>> $dayMembers
         * @var array<string, list<Media>> $dayGpsMembers
         * @var array<string, float> $dayDistanceKm
         */
        $dayMembers = [];
        $dayGpsMembers = [];
        $dayDistanceKm = [];

        foreach ($eligibleDays as $day => $list) {
            $gpsMembers = $this->filterTimestampedGpsItems($list);

            if (\count($gpsMembers) < $this->minItemsPerDay) {
                continue;
            }

            $sortedGps = $gpsMembers;
            \usort($sortedGps, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

            $distKm = 0.0;
            for ($i = 1, $n = \count($sortedGps); $i < $n; $i++) {
                $p = $sortedGps[$i - 1];
                $q = $sortedGps[$i];
                $distKm += MediaMath::haversineDistanceInMeters(
                        (float) $p->getGpsLat(),
                        (float) $p->getGpsLon(),
                        (float) $q->getGpsLat(),
                        (float) $q->getGpsLon()
                    ) / 1000.0;
            }

            if ($distKm < $this->minAwayKm) {
                continue;
            }

            $dayMembers[$day] = $list;
            $dayGpsMembers[$day] = $gpsMembers;
            $dayDistanceKm[$day] = $distKm;
        }

        if ($dayMembers === []) {
            return [];
        }

        // Sort days and find consecutive away sequences
        $days = \array_keys($dayMembers);
        \sort($days, \SORT_STRING);

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<string> $run */
        $run = [];

        $flush = function () use (&$run, &$out, $dayMembers, $dayGpsMembers, $dayDistanceKm): void {
            $runSize = \count($run);
            if ($runSize < 2) {
                $run = [];
                return;
            }
            /** @var list<Media> $all */
            $all = [];
            /** @var list<Media> $gpsMembers */
            $gpsMembers = [];
            foreach ($run as $d) {
                foreach ($dayMembers[$d] as $m) {
                    $all[] = $m;
                }
                foreach ($dayGpsMembers[$d] as $m) {
                    $gpsMembers[] = $m;
                }
            }
            $nights = \max(0, \count($run) - 1);
            if ($nights < $this->minNights) {
                $run = [];
                return;
            }

            if ($gpsMembers === []) {
                $run = [];
                return;
            }
            $centroid = MediaMath::centroid($gpsMembers);
            $time     = MediaMath::timeRange($all);

            $totalDistanceKm = 0.0;
            foreach ($run as $day) {
                $totalDistanceKm += $dayDistanceKm[$day] ?? 0.0;
            }
            $averageDistanceKm = $runSize > 0 ? $totalDistanceKm / $runSize : 0.0;
            if ($averageDistanceKm < $this->minAwayKm) {
                $run = [];
                return;
            }

            $out[] = new ClusterDraft(
                algorithm: 'long_trip',
                params: [
                    'nights'      => $nights,
                    'distance_km' => $averageDistanceKm,
                    'time_range'  => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $all)
            );

            $run = [];
        };

        $prev = null;
        foreach ($days as $d) {
            if ($prev !== null && $this->isNextDay($prev, $d) === false) {
                $flush();
            }
            $run[] = $d;
            $prev = $d;
        }
        $flush();

        return $out;
    }
}
