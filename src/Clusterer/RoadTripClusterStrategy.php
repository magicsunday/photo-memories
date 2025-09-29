<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use InvalidArgumentException;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Detects multi-day road trips based on daily traveled distance (from GPS track).
 */
final readonly class RoadTripClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;
    use MediaFilterTrait;

    public function __construct(
        private string $timezone = 'Europe/Berlin',
        private float $minDailyKm = 120.0,
        // Counts only media items that already contain GPS coordinates.
        private int $minItemsPerDay = 8,
        private int $minNights = 3,       // => at least 4 days
        private int $minItemsTotal = 40
    ) {
        if ($this->minDailyKm <= 0.0) {
            throw new InvalidArgumentException('minDailyKm must be > 0.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        if ($this->minNights < 1) {
            throw new InvalidArgumentException('minNights must be >= 1.');
        }

        if ($this->minItemsTotal < 1) {
            throw new InvalidArgumentException('minItemsTotal must be >= 1.');
        }
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

        $timestampedGpsItems = $this->filterTimestampedGpsItems($items);

        if ($timestampedGpsItems === []) {
            return [];
        }

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];
        foreach ($timestampedGpsItems as $m) {
            $t = $m->getTakenAt();
            \assert($t instanceof DateTimeImmutable);
            $d = $t->setTimezone($tz)->format('Y-m-d');
            $byDay[$d] ??= [];
            $byDay[$d][] = $m;
        }

        $eligibleDays = $this->filterGroupsByMinItems($byDay, $this->minItemsPerDay);

        /** @var array<string, list<Media>> $travelDayLists */
        $travelDayLists = $this->filterGroups(
            $eligibleDays,
            function (array $list): bool {
                $sorted = $list;
                \usort($sorted, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

                $km = 0.0;
                for ($i = 1, $n = \count($sorted); $i < $n; $i++) {
                    $p = $sorted[$i - 1];
                    $q = $sorted[$i];
                    $km += MediaMath::haversineDistanceInMeters(
                            (float) $p->getGpsLat(),
                            (float) $p->getGpsLon(),
                            (float) $q->getGpsLat(),
                            (float) $q->getGpsLon()
                        ) / 1000.0;
                }

                return $km >= $this->minDailyKm;
            }
        );

        if ($travelDayLists === []) {
            return [];
        }

        $travelDays = \array_keys($travelDayLists);
        \sort($travelDays, \SORT_STRING);

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<string> $run */
        $run = [];

        $flush = function () use (&$run, &$out, $travelDayLists): void {
            if ($run === []) {
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
                foreach ($travelDayLists[$d] as $m) {
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
