<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Outdoor festival/open-air sessions in summer months.
 */
final class FestivalSummerClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $sessionGapSeconds = 3 * 3600,
        private readonly float $radiusMeters = 600.0,
        private readonly int $minItems = 8,
        private readonly int $startMonth = 6,
        private readonly int $endMonth = 9,
        private readonly int $afternoonStartHour = 14,
        private readonly int $lateNightCutoffHour = 2
    ) {
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
        }
        foreach ([$this->startMonth, $this->endMonth] as $month) {
            if ($month < 1 || $month > 12) {
                throw new \InvalidArgumentException('Months must be within 1..12.');
            }
        }
        if ($this->startMonth > $this->endMonth) {
            throw new \InvalidArgumentException('startMonth must be <= endMonth.');
        }
        foreach ([$this->afternoonStartHour, $this->lateNightCutoffHour] as $hour) {
            if ($hour < 0 || $hour > 23) {
                throw new \InvalidArgumentException('Hour bounds must be within 0..23.');
            }
        }
        if ($this->afternoonStartHour <= $this->lateNightCutoffHour) {
            throw new \InvalidArgumentException('afternoonStartHour must be greater than lateNightCutoffHour.');
        }
    }

    public function name(): string
    {
        return 'festival_summer';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var list<Media> $cand */
        $cand = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($tz);
            $mon = (int) $local->format('n');
            if ($mon < $this->startMonth || $mon > $this->endMonth) {
                continue;
            }
            $h = (int) $local->format('G');
            if ($h > $this->lateNightCutoffHour && $h < $this->afternoonStartHour) {
                continue;
            }
            $path = \strtolower($m->getPath());
            if (!$this->looksFestival($path)) {
                continue;
            }
            $cand[] = $m;
        }

        if (\count($cand) < $this->minItems) {
            return [];
        }

        \usort($cand, static fn (Media $a, Media $b): int =>
            ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<Media> $buf */
        $buf = [];
        $last = null;

        $flush = function () use (&$buf, &$out): void {
            if (\count($buf) < $this->minItems) {
                $buf = [];
                return;
            }

            $gps = \array_values(\array_filter($buf, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            $centroid = $gps !== [] ? MediaMath::centroid($gps) : ['lat' => 0.0, 'lon' => 0.0];

            // compactness for open-air area
            $ok = true;
            foreach ($gps as $m) {
                $d = MediaMath::haversineDistanceInMeters(
                    (float) $centroid['lat'], (float) $centroid['lon'],
                    (float) $m->getGpsLat(), (float) $m->getGpsLon()
                );
                if ($d > $this->radiusMeters) {
                    $ok = false;
                    break;
                }
            }
            if ($ok === false) {
                $buf = [];
                return;
            }

            $time = MediaMath::timeRange($buf);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $buf)
            );
            $buf = [];
        };

        foreach ($cand as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts === null) {
                continue;
            }
            if ($last !== null && ($ts - $last) > $this->sessionGapSeconds) {
                $flush();
            }
            $buf[] = $m;
            $last = $ts;
        }
        $flush();

        return $out;
    }

    private function looksFestival(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = [
            'festival', 'open air', 'openair', 'rock am ring', 'wacken',
            'lollapalooza', 'fusion festival', 'parookaville', 'deichbrand',
            'bühne', 'buehne', 'stage', 'headliner'
        ];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
