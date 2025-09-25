<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Morning café/breakfast moments based on time and keywords, spatially compact.
 */
final class MorningCoffeeClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $sessionGapSeconds = 90 * 60,
        private readonly float $radiusMeters = 200.0,
        private readonly int $minItems = 3,
        private readonly int $minHour = 7,
        private readonly int $maxHour = 10
    ) {
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
        }
        if ($this->minHour < 0 || $this->minHour > 23 || $this->maxHour < 0 || $this->maxHour > 23) {
            throw new \InvalidArgumentException('Hour bounds must be within 0..23.');
        }
        if ($this->minHour > $this->maxHour) {
            throw new \InvalidArgumentException('minHour must be <= maxHour.');
        }
    }

    public function name(): string
    {
        return 'morning_coffee';
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
            $h = (int)$t->setTimezone($tz)->format('G'); 
            if ($h < $this->minHour || $h > $this->maxHour) {
                continue;
            }
            $path = \strtolower($m->getPath());
            if (!$this->looksLikeCafe($path)) {
                continue;
            }
            $cand[] = $m;
        }

        if (\count($cand) < $this->minItems) {
            return [];
        }

        \usort($cand, static fn(Media $a, Media $b): int =>
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
            $gps = \array_values(\array_filter($buf, static fn(Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            $centroid = $gps !== [] ? MediaMath::centroid($gps) : ['lat' => 0.0, 'lon' => 0.0];

            // compactness
            $ok = true;
            foreach ($gps as $m) {
                $d = MediaMath::haversineDistanceInMeters(
                    (float)$centroid['lat'], (float)$centroid['lon'],
                    (float)$m->getGpsLat(), (float)$m->getGpsLon()
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
                centroid: ['lat' => (float)$centroid['lat'], 'lon' => (float)$centroid['lon']],
                members: \array_map(static fn(Media $m): int => $m->getId(), $buf)
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

    private function looksLikeCafe(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = ['cafe', 'café', 'coffee', 'kaffee', 'frühstück', 'fruehstueck', 'bakery', 'bäckerei', 'baeckerei', 'brunch', 'espresso', 'barista'];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
