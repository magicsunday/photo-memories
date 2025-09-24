<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Groups hiking/adventure sessions based on keywords; validates by traveled distance if GPS is available.
 */
final class HikeAdventureClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly int $sessionGapSeconds = 3 * 3600,
        private readonly float $minDistanceKm = 6.0, // require at least ~6km if GPS is present
        private readonly int $minItems = 6,
        private readonly int $minItemsNoGps = 12 // stricter if no GPS available
    ) {
    }

    public function name(): string
    {
        return 'hike_adventure';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $cand */
        $cand = [];
        foreach ($items as $m) {
            $t  = $m->getTakenAt();
            $pl = \strtolower($m->getPath());
            if ($t !== null && $this->looksHike($pl)) {
                $cand[] = $m;
            }
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
            $n = \count($buf);
            if ($n < $this->minItems) {
                $buf = [];
                return;
            }

            // If GPS available, require minimum traveled distance
            $withGps = \array_values(\array_filter($buf, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));

            if ($withGps !== []) {
                \usort($withGps, static fn (Media $a, Media $b): int =>
                    ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
                );
                $km = 0.0;
                for ($i = 1, $k = \count($withGps); $i < $k; $i++) {
                    $p = $withGps[$i - 1];
                    $q = $withGps[$i];
                    $km += MediaMath::haversineDistanceInMeters(
                            (float) $p->getGpsLat(),
                            (float) $p->getGpsLon(),
                            (float) $q->getGpsLat(),
                            (float) $q->getGpsLon()
                        ) / 1000.0;
                }
                if ($km < $this->minDistanceKm) {
                    $buf = [];
                    return;
                }
            } else {
                // No GPS: require more items to reduce false positives
                if ($n < $this->minItemsNoGps) {
                    $buf = [];
                    return;
                }
            }

            $centroid = MediaMath::centroid($buf);
            $time     = MediaMath::timeRange($buf);

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

    private function looksHike(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = [
            'wander', 'wanderung', 'trail', 'hike', 'hiking', 'gipfel',
            'alpen', 'dolomiten', 'pass', 'berg', 'berge', 'klettersteig',
        ];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
