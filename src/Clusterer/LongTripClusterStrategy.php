<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Detects multi-night trips away from home based on per-day centroids and distance threshold.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 90])]
final class LongTripClusterStrategy implements ClusterStrategyInterface
{
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

        /** @var array<string, bool> $isAway */
        $isAway = [];
        /** @var array<string, list<Media>> $usableDayItems */
        $usableDayItems = [];

        foreach ($byDay as $day => $list) {
            if (\count($list) < $this->minItemsPerDay) {
                continue;
            }
            $gps = \array_values(\array_filter($list, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            if ($gps === []) {
                continue;
            }
            $centroid = MediaMath::centroid($gps);
            $dist = MediaMath::haversineDistanceInMeters(
                    (float) $centroid['lat'],
                    (float) $centroid['lon'],
                    (float) $this->homeLat,
                    (float) $this->homeLon
                ) / 1000.0;
            $isAway[$day] = $dist >= $this->minAwayKm;
            if ($isAway[$day] === true) {
                $usableDayItems[$day] = $list;
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

        $flush = function () use (&$run, &$out, $usableDayItems): void {
            if (\count($run) < 2) {
                $run = [];
                return;
            }
            /** @var list<Media> $all */
            $all = [];
            foreach ($run as $d) {
                foreach ($usableDayItems[$d] as $m) {
                    $all[] = $m;
                }
            }
            $nights = \max(0, \count($run) - 1);
            if ($nights < $this->minNights) {
                $run = [];
                return;
            }
            $centroid = MediaMath::centroid($all);
            $time     = MediaMath::timeRange($all);

            $out[] = new ClusterDraft(
                algorithm: 'long_trip',
                params: [
                    'nights'      => $nights,
                    'distance_km' => $this->minAwayKm,
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

    private function isNextDay(string $a, string $b): bool
    {
        $ta = \strtotime($a . ' 00:00:00');
        $tb = \strtotime($b . ' 00:00:00');
        if ($ta === false || $tb === false) {
            return false;
        }
        return ($tb - $ta) === 86400;
    }
}
