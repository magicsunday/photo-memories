<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Sports events based on keywords (stadium/match/club names) and weekend bias.
 */
final class SportsEventClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $sessionGapSeconds = 3 * 3600,
        private readonly float $radiusMeters = 500.0,
        private readonly int $minItems = 5,
        private readonly bool $preferWeekend = true
    ) {
        if ($this->sessionGapSeconds < 1) {
            throw new \InvalidArgumentException('sessionGapSeconds must be >= 1.');
        }
        if ($this->radiusMeters <= 0.0) {
            throw new \InvalidArgumentException('radiusMeters must be > 0.');
        }
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'sports_event';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var list<Media> $cand */
        $cand = \array_values(\array_filter(
            $items,
            function (Media $m) use ($tz): bool {
                $t = $m->getTakenAt();
                if (!$t instanceof DateTimeImmutable) {
                    return false;
                }

                $path = \strtolower($m->getPath());
                if (!$this->looksSporty($path)) {
                    return false;
                }

                if ($this->preferWeekend) {
                    $dow = (int) $t->setTimezone($tz)->format('N');
                    if ($dow !== 6 && $dow !== 7) {
                        return false;
                    }
                }

                return true;
            }
        ));

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

            // compactness (stadium/arena)
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

    private function looksSporty(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = [
            'stadion', 'arena', 'sportpark', 'eishalle',
            'match', 'spiel', 'game', 'derby',
            'fussball', 'fußball', 'football', 'soccer',
            'handball', 'basketball', 'eishockey', 'hockey',
            'tennis', 'marathon', 'lauf', 'run', 'triathlon',
            'bundesliga', 'dfb', 'uefa', 'champions', 'cup'
        ];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
