<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function abs;
use function array_key_exists;
use function array_map;
use function assert;
use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function usort;

/**
 * Marks "travel days" by summing GPS path distance within the day.
 */
final readonly class TransitTravelDayClusterStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterLocationMetadataTrait;
    use ProgressAwareClusterTrait;

    private float $minTravelKm;

    private int $minItemsPerDay;

    private float $minSegmentSpeedMps;

    private int $minFastSegments;

    private float $maxHeadingChangeDeg;

    private int $minConsistentHeadingSegments;

    private string $activeProfile;

    /** @var array<string,array<string,float|int>> */
    private array $profileThresholds;

    public function __construct(
        private LocalTimeHelper $localTimeHelper,
        private LocationHelper $locationHelper,
        float $minTravelKm = 60.0,
        // Counts only media items that already contain GPS coordinates.
        int $minItemsPerDay = 5,
        float $minSegmentSpeedMps = 5.0,
        int $minFastSegments = 3,
        float $maxHeadingChangeDeg = 90.0,
        int $minConsistentHeadingSegments = 2,
        array $profileThresholds = [],
        string $activeProfile = 'default',
    ) {
        $this->profileThresholds = $this->sanitizeProfileThresholds($profileThresholds);

        $resolved = $this->resolveProfileThresholds(
            [
                'min_travel_km'                   => $minTravelKm,
                'min_items_per_day'               => $minItemsPerDay,
                'min_segment_speed_mps'           => $minSegmentSpeedMps,
                'min_fast_segments'               => $minFastSegments,
                'max_heading_change_deg'          => $maxHeadingChangeDeg,
                'min_consistent_heading_segments' => $minConsistentHeadingSegments,
            ],
            $activeProfile
        );

        $this->minTravelKm                 = $resolved['min_travel_km'];
        $this->minItemsPerDay              = $resolved['min_items_per_day'];
        $this->minSegmentSpeedMps          = $resolved['min_segment_speed_mps'];
        $this->minFastSegments             = $resolved['min_fast_segments'];
        $this->maxHeadingChangeDeg         = $resolved['max_heading_change_deg'];
        $this->minConsistentHeadingSegments = $resolved['min_consistent_heading_segments'];
        $this->activeProfile               = $resolved['profile'];

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

                    $segmentMeters = MediaMath::haversineDistanceInMeters(
                        $pLat,
                        $pLon,
                        $qLat,
                        $qLon
                    );

                    $distKm += $segmentMeters / 1000.0;

                    ++$segmentCount;

                    $segmentSpeedMps = self::resolveSegmentSpeed($p->getGpsSpeedMps(), $q->getGpsSpeedMps());

                    if ($segmentSpeedMps === null) {
                        $pTime = $p->getTakenAt();
                        $qTime = $q->getTakenAt();

                        if ($pTime instanceof DateTimeImmutable && $qTime instanceof DateTimeImmutable) {
                            $deltaSeconds = $qTime->getTimestamp() - $pTime->getTimestamp();

                            if ($deltaSeconds > 0) {
                                $segmentSpeedMps = $segmentMeters / $deltaSeconds;
                            }
                        }
                    }

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

            $params['travel_profile'] = $this->activeProfile;
            $params['travel_thresholds'] = [
                'min_travel_km'                   => $this->minTravelKm,
                'min_items_per_day'               => $this->minItemsPerDay,
                'min_segment_speed_mps'           => $this->minSegmentSpeedMps,
                'min_fast_segments'               => $this->minFastSegments,
                'max_heading_change_deg'          => $this->maxHeadingChangeDeg,
                'min_consistent_heading_segments' => $this->minConsistentHeadingSegments,
            ];

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => $centroid['lat'], 'lon' => $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }

    /**
     * @param array<string,float|int> $base
     *
     * @return array{
     *     min_travel_km: float,
     *     min_items_per_day: int,
     *     min_segment_speed_mps: float,
     *     min_fast_segments: int,
     *     max_heading_change_deg: float,
     *     min_consistent_heading_segments: int,
     *     profile: string,
     * }
     */
    private function resolveProfileThresholds(array $base, string $activeProfile): array
    {
        $profileName = $activeProfile;
        $profile     = $this->profileThresholds[$activeProfile] ?? null;

        if ($profile === null && isset($this->profileThresholds['default'])) {
            $profileName = 'default';
            $profile     = $this->profileThresholds['default'];
        }

        if (is_array($profile)) {
            foreach ($profile as $key => $value) {
                if (!is_string($key) || !array_key_exists($key, $base) || !is_numeric($value)) {
                    continue;
                }

                $base[$key] = is_float($base[$key]) ? (float) $value : (int) $value;
            }
        }

        return [
            'min_travel_km'                   => (float) $base['min_travel_km'],
            'min_items_per_day'               => (int) $base['min_items_per_day'],
            'min_segment_speed_mps'           => (float) $base['min_segment_speed_mps'],
            'min_fast_segments'               => (int) $base['min_fast_segments'],
            'max_heading_change_deg'          => (float) $base['max_heading_change_deg'],
            'min_consistent_heading_segments' => (int) $base['min_consistent_heading_segments'],
            'profile'                         => $profileName,
        ];
    }

    /**
     * @param array<string,mixed> $profiles
     *
     * @return array<string,array<string,float|int>>
     */
    private function sanitizeProfileThresholds(array $profiles): array
    {
        $result = [];

        foreach ($profiles as $profileName => $values) {
            if (!is_string($profileName) || $profileName === '' || !is_array($values)) {
                continue;
            }

            $sanitized = [];
            foreach ($values as $key => $value) {
                if (!is_string($key) || !is_numeric($value)) {
                    continue;
                }

                $sanitized[$key] = $value;
            }

            if ($sanitized === []) {
                continue;
            }

            $result[$profileName] = $sanitized;
        }

        return $result;
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
    /**
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void $update
     *
     * @return list<ClusterDraft>
     */
    public function clusterWithProgress(array $items, callable $update): array
    {
        return $this->runWithDefaultProgress($items, $update, fn (array $payload): array => $this->cluster($payload));
    }

}
