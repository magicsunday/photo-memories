<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Context;
use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ContextualClusterBridgeTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Clusterer\Support\GeoTemporalClusterTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\GeoCell;
use MagicSunday\Memories\Utility\LocationHelper;

use function count;
use function min;

/**
 * Class LocationSimilarityStrategy.
 */
final readonly class LocationSimilarityStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use ContextualClusterBridgeTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use GeoTemporalClusterTrait;
    use MediaFilterTrait;
    use ProgressAwareClusterTrait;

    private const MAX_WINDOW_SECONDS = 10800;

    private const MAX_RADIUS_METERS = 250.0;

    private GeoDbscanHelper $dbscanHelper;

    public function __construct(
        private LocationHelper $locationHelper,
        private float $radiusMeters = 150.0,
        private int $minItemsPerPlace = 5,
        private int $maxSpanHours = 24,
        ?GeoDbscanHelper $dbscanHelper = null,
    ) {
        if ($this->radiusMeters <= 0.0) {
            throw new InvalidArgumentException('radiusMeters must be > 0.');
        }

        if ($this->minItemsPerPlace < 1) {
            throw new InvalidArgumentException('minItemsPerPlace must be >= 1.');
        }

        if ($this->maxSpanHours < 0) {
            throw new InvalidArgumentException('maxSpanHours must be >= 0.');
        }

        $this->dbscanHelper = $dbscanHelper ?? new GeoDbscanHelper();
    }

    public function name(): string
    {
        return 'location_similarity';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $withTimestamp */
        $withTimestamp = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $byLocality */
        $byLocality = [];
        /** @var list<Media> $noLocality */
        $noLocality = [];

        foreach ($withTimestamp as $m) {
            $key = $this->locationHelper->localityKeyForMedia($m);
            if ($key !== null) {
                $byLocality[$key] ??= [];
                $byLocality[$key][] = $m;
            } else {
                $noLocality[] = $m;
            }
        }

        /** @var array<string, list<Media>> $eligibleLocalities */
        $eligibleLocalities = $this->filterGroupsByMinItems($byLocality, $this->minItemsPerPlace);

        $drafts = [];

        foreach ($eligibleLocalities as $key => $group) {
            $buckets = $this->buildGeoTemporalBuckets(
                $group,
                $this->dbscanHelper,
                $this->minItemsPerPlace,
                $this->resolveRadiusMeters(),
                $this->resolveWindowSeconds(),
            );

            foreach ($buckets as $bucket) {
                $drafts[] = $this->makeDraft($bucket, $key);
            }
        }

        if ($noLocality !== []) {
            $fallbackBuckets = $this->buildGeoTemporalBuckets(
                $noLocality,
                $this->dbscanHelper,
                $this->minItemsPerPlace,
                $this->resolveRadiusMeters(),
                $this->resolveWindowSeconds(),
            );

            foreach ($fallbackBuckets as $bucket) {
                $drafts[] = $this->makeDraft($bucket, null);
            }
        }

        return $drafts;
    }

    /**
     * @param list<Media> $bucket
     */
    private function makeDraft(array $bucket, ?string $placeKey): ClusterDraft
    {
        $centroid = $this->computeCentroid($bucket);
        $range    = $this->computeTimeRange($bucket);

        $params = [
            'time_range'    => $range,
            'window_bounds' => $range,
            'members_count' => count($bucket),
        ];

        if ($placeKey !== null) {
            $params['place_key'] = $placeKey;
        }

        $lat = $centroid['lat'] ?? null;
        $lon = $centroid['lon'] ?? null;
        if ($lat !== null && $lon !== null) {
            $params['centroid_cell7'] = GeoCell::fromPoint($lat, $lon, 7);
        }

        $params = $this->appendLocationMetadata($bucket, $params);

        $tagMetadata = $this->collectDominantTags($bucket);
        foreach ($tagMetadata as $paramKey => $value) {
            $params[$paramKey] = $value;
        }

        return new ClusterDraft(
            algorithm: $this->name(),
            params: $params,
            centroid: $centroid,
            members: $this->toMemberIds($bucket)
        );
    }
    /**
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void $update
     *
     * @return list<ClusterDraft>
     */
    public function clusterWithProgress(array $items, Context $ctx, callable $update): array
    {
        return $this->runWithDefaultProgress(
            $items,
            $ctx,
            $update,
            fn (array $payload, Context $context): array => $this->draft($payload, $context)
        );
    }

    private function resolveWindowSeconds(): int
    {
        $seconds = (int) ($this->maxSpanHours * 3600);

        return min($seconds, self::MAX_WINDOW_SECONDS);
    }

    private function resolveRadiusMeters(): float
    {
        return min($this->radiusMeters, self::MAX_RADIUS_METERS);
    }

}
