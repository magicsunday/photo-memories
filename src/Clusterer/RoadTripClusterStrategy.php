<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Detects multi-day road trips based on daily traveled distance (from GPS track).
 */
final class RoadTripClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly float $minDailyKm = 120.0,
        private readonly int $minGpsSamplesPerDay = 8,
        private readonly int $minNights = 3,       // => at least 4 days
        private readonly int $minItemsTotal = 40
    ) {
    }

    public function name(): string
    {
        return 'road_trip';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];
        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            if ($m->getGpsLat() === null || $m->getGpsLon() === null) {
                continue;
            }
            $d = $t->setTimezone($tz)->format('Y-m-d');
            $byDay[$d] ??= [];
            $byDay[$d][] = $m;
        }

        /** @var list<string> $travelDays */
        $travelDays = [];
        foreach ($byDay as $day => $list) {
            if (\count($list) < $this->minGpsSamplesPerDay) {
                continue;
            }
            \usort($list, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
            $km = 0.0;
            for ($i = 1, $n = \count($list); $i < $n; $i++) {
                $p = $list[$i - 1];
                $q = $list[$i];
                $km += MediaMath::haversineDistanceInMeters(
                        (float) $p->getGpsLat(),
                        (float) $p->getGpsLon(),
                        (float) $q->getGpsLat(),
                        (float) $q->getGpsLon()
                    ) / 1000.0;
            }
            if ($km >= $this->minDailyKm) {
                $travelDays[] = $day;
            }
        }

        if ($travelDays === []) {
            return [];
        }

        \sort($travelDays, \SORT_STRING);

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<string> $run */
        $run = [];

        $flush = function () use (&$run, &$out, $byDay): void {
            if (\count($run) === 0) {
                return;
            }
            $nights = \count($run) - 1;
            if ($nights < $this->minNights) {
                $run = [];
                return;
            }
            /** @var list<Media> $members */
            $members = [];
            foreach ($run as $d) {
                foreach ($byDay[$d] as $m) {
                    $members[] = $m;
                }
            }
            if (\count($members) < $this->minItemsTotal) {
                $run = [];
                return;
            }

            \usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
            $centroid = MediaMath::centroid($members);
            $time     = MediaMath::timeRange($members);

            $out[] = new ClusterDraft(
                algorithm: 'road_trip',
                params: [
                    'nights'     => $nights,
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $members)
            );

            $run = [];
        };

        $prev = null;
        foreach ($travelDays as $d) {
            if ($prev !== null && !$this->isNextDay($prev, $d)) {
                $flush();
            }
            $run[] = $d;
            $prev  = $d;
        }
        $flush();

        return $out;
    }
}
