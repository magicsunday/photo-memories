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
        if ($this->timeGapSeconds < 1) {
            throw new \InvalidArgumentException('timeGapSeconds must be >= 1.');
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

        /** @var list<list<Media>> $runs */
        $runs = [];
        /** @var list<Media> $buf */
        $buf = [];
        $lastTs = null;

        foreach ($withTime as $m) {
            $ts = (int) $m->getTakenAt()->getTimestamp();

            if ($lastTs !== null && ($ts - $lastTs) > $this->timeGapSeconds && $buf !== []) {
                $runs[] = $buf;
                $buf    = [];
            }

            $buf[]  = $m;
            $lastTs = $ts;
        }

        if ($buf !== []) {
            $runs[] = $buf;
        }

        $eligibleRuns = \array_values(\array_filter(
            $runs,
            fn (array $list): bool => \count($list) >= $this->minItems
        ));

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleRuns as $run) {
            $gps = \array_values(\array_filter($run, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            $centroid = $gps !== []
                ? MediaMath::centroid($gps)
                : ['lat' => 0.0, 'lon' => 0.0];

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

            if (!$ok) {
                continue;
            }

            $time = MediaMath::timeRange($run);
            $out[] = new ClusterDraft(
                algorithm: 'cross_dimension',
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $run)
            );
        }

        return $out;
    }
}
