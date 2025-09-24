<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Clusters items that are both temporally and spatially close.
 * Sliding-session approach with time gap and radius constraints.
 */
final class CrossDimensionClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly int $timeGapSeconds = 2 * 3600,   // 2h
        private readonly float $radiusMeters = 150.0,      // 150 m
        private readonly int $minItems = 6
    ) {
    }

    public function name(): string
    {
        return 'cross_dimension';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        // Filter: need time; prefer GPS (we allow some without GPS as long as cluster centroid is stable)
        $withTime = \array_values(\array_filter(
            $items,
            static fn (Media $m): bool => $m->getTakenAt() instanceof DateTimeImmutable
        ));

        if (\count($withTime) < $this->minItems) {
            return [];
        }

        \usort($withTime, static function (Media $a, Media $b): int {
            return $a->getTakenAt() <=> $b->getTakenAt();
        });

        /** @var list<ClusterDraft> $out */
        $out = [];

        /** @var list<Media> $buf */
        $buf = [];
        $lastTs = null;

        $flush = function () use (&$buf, &$out): void {
            if (\count($buf) < $this->minItems) {
                $buf = [];
                return;
            }

            // compute centroid from GPS-having items; if none, centroid (0,0)
            $gps = \array_values(\array_filter($buf, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            $centroid = $gps !== []
                ? MediaMath::centroid($gps)
                : ['lat' => 0.0, 'lon' => 0.0];

            // Validate spatial compactness: max distance to centroid <= radius
            $ok = true;
            foreach ($gps as $m) {
                $dist = MediaMath::haversineDistanceInMeters(
                    $centroid['lat'],
                    $centroid['lon'],
                    (float) $m->getGpsLat(),
                    (float) $m->getGpsLon()
                );

                if ($dist > $this->radiusMeters) {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                $time = MediaMath::timeRange($buf);
                $out[] = new ClusterDraft(
                    algorithm: 'cross_dimension',
                    params: [
                        'time_range' => $time,
                    ],
                    centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                    members: \array_map(static fn (Media $m): int => $m->getId(), $buf)
                );
            }

            $buf = [];
        };

        foreach ($withTime as $m) {
            $ts = (int) $m->getTakenAt()->getTimestamp();

            if ($lastTs !== null && ($ts - $lastTs) > $this->timeGapSeconds) {
                $flush();
            }

            $buf[] = $m;
            $lastTs = $ts;
        }
        $flush();

        return $out;
    }
}
