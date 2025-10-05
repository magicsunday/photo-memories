<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function count;
use function usort;

/**
 * Clusters items that are both temporally and spatially close.
 * Sliding-session approach with time gap and radius constraints.
 */
final readonly class CrossDimensionClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;
    use MediaFilterTrait;

    private ClusterQualityAggregator $qualityAggregator;

    public function __construct(
        private int $timeGapSeconds = 2 * 3600,   // 2h
        private float $radiusMeters = 150.0,      // 150 m
        private int $minItemsPerRun = 6,
        ?ClusterQualityAggregator $qualityAggregator = null,
    ) {
        if ($this->timeGapSeconds < 1) {
            throw new InvalidArgumentException('timeGapSeconds must be >= 1.');
        }

        if ($this->radiusMeters <= 0.0) {
            throw new InvalidArgumentException('radiusMeters must be > 0.');
        }

        if ($this->minItemsPerRun < 1) {
            throw new InvalidArgumentException('minItemsPerRun must be >= 1.');
        }

        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
    }

    public function name(): string
    {
        return 'cross_dimension';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        // Filter: need time; prefer GPS (we allow some without GPS as long as cluster centroid is stable)
        $withTime = $this->filterTimestampedItems($items);

        if (count($withTime) < $this->minItemsPerRun) {
            return [];
        }

        usort($withTime, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        /** @var list<list<Media>> $runs */
        $runs = [];
        /** @var list<Media> $buf */
        $buf    = [];
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

        $eligibleRuns = $this->filterListsByMinItems($runs, $this->minItemsPerRun);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleRuns as $run) {
            $gps      = $this->filterGpsItems($run);
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

            $time        = $this->computeTimeRange($run);
            $params      = [
                'time_range' => $time,
            ];
            $tagMetadata = $this->collectDominantTags($run);
            foreach ($tagMetadata as $key => $value) {
                $params[$key] = $value;
            }

            $qualityParams = $this->qualityAggregator->buildParams($run);
            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $out[] = new ClusterDraft(
                algorithm: 'cross_dimension',
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: $this->toMemberIds($run)
            );
        }

        return $out;
    }
}
