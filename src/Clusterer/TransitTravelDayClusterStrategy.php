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
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function abs;
use function array_map;
use function assert;
use function count;
use function usort;

/**
 * Marks "travel days" by summing GPS path distance within the day.
 */
final readonly class TransitTravelDayClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterLocationMetadataTrait;

    public function __construct(
        private LocalTimeHelper $localTimeHelper,
        private LocationHelper $locationHelper,
        private float $minTravelKm = 60.0,
        // Counts only media items that already contain GPS coordinates.
        private int $minItemsPerDay = 5,
        private float $minSegmentSpeedMps = 5.0,
        private int $minFastSegments = 3,
        private float $maxHeadingChangeDeg = 90.0,
        private int $minConsistentHeadingSegments = 2,
    ) {
        if ($this->minTravelKm <= 0.0) {
            throw new InvalidArgumentException('minTravelKm must be > 0.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        if ($this->minSegmentSpeedMps <= 0.0) {
            throw new InvalidArgumentException('minSegmentSpeedMps must be > 0.');
        }

        if ($this->minFastSegments < 0) {
            throw new InvalidArgumentException('minFastSegments must be >= 0.');
        }

        if ($this->maxHeadingChangeDeg <= 0.0 || $this->maxHeadingChangeDeg > 180.0) {
            throw new InvalidArgumentException('maxHeadingChangeDeg must be within (0, 180].');
        }

        if ($this->minConsistentHeadingSegments < 0) {
            throw new InvalidArgumentException('minConsistentHeadingSegments must be >= 0.');
        }
    }

    public function name(): string
    {
        return 'transit_travel_day';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $timestampedGpsItems = $this->filterTimestampedGpsItems($items);

        if ($timestampedGpsItems === []) {
            return [];
        }

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($timestampedGpsItems as $m) {
            $local = $this->localTimeHelper->resolve($m);
            assert($local instanceof DateTimeImmutable);
            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        $eligibleDays = $this->filterGroupsByMinItems($byDay, $this->minItemsPerDay);

        /** @var array<string, float> $dayDistanceKm */
        $dayDistanceKm = [];
        /** @var array<string, array<string, int|float|null>> $dayMovementMetrics */
        $dayMovementMetrics = [];
        $travelDays    = $this->filterGroupsWithKeys(
            $eligibleDays,
            function (array $list, string $day) use (&$dayDistanceKm, &$dayMovementMetrics): bool {
                $sorted = $list;
                usort($sorted, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

                $distKm                      = 0.0;
                $segmentCount                = 0;
                $fastSegmentCount            = 0;
                $speedSum                    = 0.0;
                $speedSamples                = 0;
                $maxSpeedMps                 = null;
                $headingChangeSum            = 0.0;
                $headingSamples              = 0;
                $consistentHeadingSegments   = 0;

                for ($i = 1, $n = count($sorted); $i < $n; ++$i) {
                    $p    = $sorted[$i - 1];
                    $q    = $sorted[$i];
                    $pLat = $p->getGpsLat();
                    $pLon = $p->getGpsLon();
                    $qLat = $q->getGpsLat();
                    $qLon = $q->getGpsLon();

                    if ($pLat === null || $pLon === null || $qLat === null || $qLon === null) {
                        continue;
                    }

                    $distKm += MediaMath::haversineDistanceInMeters(
                        $pLat,
                        $pLon,
                        $qLat,
                        $qLon
                    ) / 1000.0;

                    ++$segmentCount;

                    $segmentSpeedMps = self::resolveSegmentSpeed($p->getGpsSpeedMps(), $q->getGpsSpeedMps());

                    if ($segmentSpeedMps !== null) {
                        $speedSum     += $segmentSpeedMps;
                        ++$speedSamples;
                        if ($maxSpeedMps === null || $segmentSpeedMps > $maxSpeedMps) {
                            $maxSpeedMps = $segmentSpeedMps;
                        }

                        if ($segmentSpeedMps >= $this->minSegmentSpeedMps) {
                            ++$fastSegmentCount;
                        }
                    }

                    $headingDelta = self::resolveHeadingDelta($p->getGpsHeadingDeg(), $q->getGpsHeadingDeg());

                    if ($headingDelta !== null) {
                        $headingChangeSum += $headingDelta;
                        ++$headingSamples;

                        if ($headingDelta <= $this->maxHeadingChangeDeg) {
                            ++$consistentHeadingSegments;
                        }
                    }
                }

                if ($distKm < $this->minTravelKm) {
                    return false;
                }

                if ($fastSegmentCount < $this->minFastSegments) {
                    return false;
                }

                if (
                    $headingSamples > 0
                    && $consistentHeadingSegments < $this->minConsistentHeadingSegments
                ) {
                    return false;
                }

                $dayDistanceKm[$day] = $distKm;

                $dayMovementMetrics[$day] = [
                    'segment_count'                    => $segmentCount,
                    'fast_segment_count'               => $fastSegmentCount,
                    'fast_segment_ratio'               => $segmentCount > 0 ? $fastSegmentCount / $segmentCount : null,
                    'speed_sample_count'               => $speedSamples,
                    'avg_speed_mps'                    => $speedSamples > 0 ? $speedSum / $speedSamples : null,
                    'max_speed_mps'                    => $maxSpeedMps,
                    'heading_sample_count'             => $headingSamples,
                    'avg_heading_change_deg'           => $headingSamples > 0 ? $headingChangeSum / $headingSamples : null,
                    'consistent_heading_segment_count' => $consistentHeadingSegments,
                    'heading_consistency_ratio'        => $headingSamples > 0 ? $consistentHeadingSegments / $headingSamples : null,
                    'fast_segment_speed_threshold_mps' => $this->minSegmentSpeedMps,
                    'min_fast_segment_count_threshold' => $this->minFastSegments,
                    'max_heading_change_threshold_deg' => $this->maxHeadingChangeDeg,
                    'min_consistent_heading_segments_threshold' => $this->minConsistentHeadingSegments,
                ];

                return true;
            }
        );

        if ($travelDays === []) {
            return [];
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($travelDays as $day => $list) {
            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $params = $this->appendLocationMetadata($list, [
                'distance_km' => $dayDistanceKm[$day],
                'time_range'  => $time,
                'movement'    => $dayMovementMetrics[$day] ?? null,
            ]);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => $centroid['lat'], 'lon' => $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }

    private static function resolveSegmentSpeed(?float $pSpeedMps, ?float $qSpeedMps): ?float
    {
        if ($pSpeedMps !== null && $qSpeedMps !== null) {
            return ($pSpeedMps + $qSpeedMps) / 2.0;
        }

        if ($pSpeedMps !== null) {
            return $pSpeedMps;
        }

        return $qSpeedMps;
    }

    private static function resolveHeadingDelta(?float $pHeadingDeg, ?float $qHeadingDeg): ?float
    {
        if ($pHeadingDeg === null || $qHeadingDeg === null) {
            return null;
        }

        $delta = abs($pHeadingDeg - $qHeadingDeg);

        if ($delta > 180.0) {
            return 360.0 - $delta;
        }

        return $delta;
    }
}
