<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters home-based weekday sessions (Mon–Fri) when most photos are within a home radius.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 43])]
final class AtHomeWeekdayClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;

    public function __construct(
        private readonly ?float $homeLat = null,
        private readonly ?float $homeLon = null,
        private readonly float $homeRadiusMeters = 300.0,
        private readonly float $minHomeShare = 0.7,   // >= 70% of day's photos within radius
        private readonly int $minItemsPerDay = 4,
        private readonly int $minItemsTotal = 8,
        private readonly string $timezone = 'Europe/Berlin'
    ) {
    }

    public function name(): string
    {
        return 'at_home_weekday';
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
            $dow = (int) $local->format('N'); // 1=Mon..7=Sun
            if ($dow < 1 || $dow > 5) {      // weekdays only
                continue;
            }
            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        if ($byDay === []) {
            return [];
        }

        /** @var array<string, list<Media>> $homeOnly */
        $homeOnly = [];
        /** @var list<string> $keepDays */
        $keepDays = [];

        foreach ($byDay as $day => $list) {
            if (\count($list) < $this->minItemsPerDay) {
                continue;
            }

            $within = [];
            foreach ($list as $m) {
                $lat = $m->getGpsLat();
                $lon = $m->getGpsLon();
                if ($lat === null || $lon === null) {
                    continue;
                }
                $dist = MediaMath::haversineDistanceInMeters(
                    (float) $lat,
                    (float) $lon,
                    (float) $this->homeLat,
                    (float) $this->homeLon
                );
                if ($dist <= $this->homeRadiusMeters) {
                    $within[] = $m;
                }
            }

            if ($within === []) {
                continue;
            }

            $share = \count($within) / (float) \count($list);
            if ($share >= $this->minHomeShare) {
                $homeOnly[$day] = $within;
                $keepDays[] = $day;
            }
        }

        if ($keepDays === []) {
            return [];
        }

        \sort($keepDays, \SORT_STRING);

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<string> $run */
        $run = [];

        $flush = function () use (&$run, &$out, $homeOnly): void {
            if (\count($run) === 0) {
                return;
            }

            /** @var list<Media> $members */
            $members = [];
            foreach ($run as $d) {
                foreach ($homeOnly[$d] as $m) {
                    $members[] = $m;
                }
            }

            if (\count($members) < $this->minItemsTotal) {
                $run = [];
                return;
            }

            $centroid = MediaMath::centroid($members);
            $time     = MediaMath::timeRange($members);

            $out[] = new ClusterDraft(
                algorithm: 'at_home_weekday',
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $members)
            );

            $run = [];
        };

        $prev = null;
        foreach ($keepDays as $d) {
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
