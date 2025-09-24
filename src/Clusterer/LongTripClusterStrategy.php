<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Detects multi-night trips away from home based on per-day centroids and distance threshold.
 */
final class LongTripClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;

    public function __construct(
        /** Home base; if null, strategy is effectively disabled. */
        private readonly ?float $homeLat = null,
        private readonly ?float $homeLon = null,
        private readonly float $minAwayKm = 150.0,
        private readonly int $minNights = 3,
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $minItemsPerDay = 3
    ) {
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

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];
        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($tz);
            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        /** @var array<string, list<Media>> $usableDayItems */
        $usableDayItems = [];
        /** @var array<string, float> $dayDistance */
        $dayDistance = [];

        foreach ($byDay as $day => $list) {
            if (\count($list) < $this->minItemsPerDay) {
                continue;
            }
            $gps = \array_values(\array_filter($list, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            if (\count($gps) < $this->minItemsPerDay) {
                continue;
            }
            $centroid = MediaMath::centroid($gps);
            $dist = MediaMath::haversineDistanceInMeters(
                    (float) $centroid['lat'],
                    (float) $centroid['lon'],
                    (float) $this->homeLat,
                    (float) $this->homeLon
                ) / 1000.0;

            if ($dist >= $this->minAwayKm) {
                $usableDayItems[$day] = $list;
                $dayDistance[$day] = $dist;
            }
        }

        if ($usableDayItems === []) {
            return [];
        }

        // Sort days and find consecutive away sequences
        $days = \array_keys($usableDayItems);
        \sort($days, \SORT_STRING);

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<string> $run */
        $run = [];

        $flush = function () use (&$run, &$out, $usableDayItems, $dayDistance): void {
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
                foreach ($usableDayItems[$d] as $m) {
                    $all[] = $m;
                    if ($m->getGpsLat() !== null && $m->getGpsLon() !== null) {
                        $gpsMembers[] = $m;
                    }
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
                $totalDistanceKm += $dayDistance[$day] ?? 0.0;
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
