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
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_map;
use function assert;
use function count;
use function str_contains;
use function strtolower;
use function usort;

/**
 * Outdoor festival/open-air sessions in summer months.
 */
final readonly class FestivalSummerClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private string $timezone = 'Europe/Berlin',
        private int $sessionGapSeconds = 3 * 3600,
        private float $radiusMeters = 600.0,
        private int $minItemsPerRun = 8,
        private int $startMonth = 6,
        private int $endMonth = 9,
        private int $afternoonStartHour = 14,
        private int $lateNightCutoffHour = 2,
    ) {
        if ($this->sessionGapSeconds < 1) {
            throw new InvalidArgumentException('sessionGapSeconds must be >= 1.');
        }

        if ($this->radiusMeters <= 0.0) {
            throw new InvalidArgumentException('radiusMeters must be > 0.');
        }

        if ($this->minItemsPerRun < 1) {
            throw new InvalidArgumentException('minItemsPerRun must be >= 1.');
        }

        foreach ([$this->startMonth, $this->endMonth] as $month) {
            if ($month < 1 || $month > 12) {
                throw new InvalidArgumentException('Months must be within 1..12.');
            }
        }

        if ($this->startMonth > $this->endMonth) {
            throw new InvalidArgumentException('startMonth must be <= endMonth.');
        }

        foreach ([$this->afternoonStartHour, $this->lateNightCutoffHour] as $hour) {
            if ($hour < 0 || $hour > 23) {
                throw new InvalidArgumentException('Hour bounds must be within 0..23.');
            }
        }

        if ($this->afternoonStartHour <= $this->lateNightCutoffHour) {
            throw new InvalidArgumentException('afternoonStartHour must be greater than lateNightCutoffHour.');
        }
    }

    public function name(): string
    {
        return 'festival_summer';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var list<Media> $cand */
        $cand = $this->filterTimestampedItemsBy(
            $items,
            function (Media $m) use ($tz): bool {
                $t = $m->getTakenAt();
                assert($t instanceof DateTimeImmutable);
                $local = $t->setTimezone($tz);
                $mon   = (int) $local->format('n');
                if ($mon < $this->startMonth || $mon > $this->endMonth) {
                    return false;
                }

                $h = (int) $local->format('G');
                if ($h > $this->lateNightCutoffHour && $h < $this->afternoonStartHour) {
                    return false;
                }

                $path = strtolower($m->getPath());

                return $this->looksFestival($path);
            }
        );

        if (count($cand) < $this->minItemsPerRun) {
            return [];
        }

        usort($cand, static fn (Media $a, Media $b): int => ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<list<Media>> $runs */
        $runs = [];
        /** @var list<Media> $buf */
        $buf  = [];
        $last = null;

        foreach ($cand as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts === null) {
                continue;
            }

            if ($last !== null && ($ts - $last) > $this->sessionGapSeconds && $buf !== []) {
                $runs[] = $buf;
                $buf    = [];
            }

            $buf[] = $m;
            $last  = $ts;
        }

        if ($buf !== []) {
            $runs[] = $buf;
        }

        $eligibleRuns = $this->filterListsByMinItems($runs, $this->minItemsPerRun);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleRuns as $run) {
            $gps      = $this->filterGpsItems($run);
            $centroid = $gps !== [] ? MediaMath::centroid($gps) : ['lat' => 0.0, 'lon' => 0.0];

            // compactness for open-air area
            $ok = true;
            foreach ($gps as $m) {
                $d = MediaMath::haversineDistanceInMeters(
                    (float) $centroid['lat'],
                    (float) $centroid['lon'],
                    (float) $m->getGpsLat(),
                    (float) $m->getGpsLon()
                );
                if ($d > $this->radiusMeters) {
                    $ok = false;
                    break;
                }
            }

            if ($ok === false) {
                continue;
            }

            $time = MediaMath::timeRange($run);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $run)
            );
        }

        return $out;
    }

    private function looksFestival(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = [
            'festival', 'open air', 'openair', 'rock am ring', 'wacken',
            'lollapalooza', 'fusion festival', 'parookaville', 'deichbrand',
            'bühne', 'buehne', 'stage', 'headliner',
        ];
        foreach ($kw as $k) {
            if (str_contains($pathLower, $k)) {
                return true;
            }
        }

        return false;
    }
}
