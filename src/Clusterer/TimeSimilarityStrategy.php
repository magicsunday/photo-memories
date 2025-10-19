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
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Clusterer\Support\GeoTemporalClusterTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\GeoCell;
use MagicSunday\Memories\Utility\LocationHelper;

use function array_map;
use function count;
use function min;
use function usort;

/**
 * Class TimeSimilarityStrategy.
 */
final readonly class TimeSimilarityStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use ContextualClusterBridgeTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use GeoTemporalClusterTrait;
    use MediaFilterTrait;
    use ProgressAwareClusterTrait;

    private LocationHelper $locationHelper;

    private ClusterQualityAggregator $qualityAggregator;

    private GeoDbscanHelper $dbscanHelper;

    private const MAX_WINDOW_SECONDS = 10800;

    private const CLUSTER_RADIUS_METERS = 250.0;

    public function __construct(
        LocationHelper $locHelper,
        private int $maxGapSeconds = 21600,
        private int $minItemsPerBucket = 5,
        ?ClusterQualityAggregator $qualityAggregator = null,
        ?GeoDbscanHelper $dbscanHelper = null,
    ) {
        if ($this->maxGapSeconds < 1) {
            throw new InvalidArgumentException('maxGapSeconds must be >= 1.');
        }

        if ($this->minItemsPerBucket < 1) {
            throw new InvalidArgumentException('minItemsPerBucket must be >= 1.');
        }

        $this->locationHelper    = $locHelper;
        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
        $this->dbscanHelper      = $dbscanHelper ?? new GeoDbscanHelper();
    }

    public function name(): string
    {
        return 'time_similarity';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $withTs = $this->filterTimestampedItems($items);

        $buckets = $this->buildGeoTemporalBuckets(
            $withTs,
            $this->dbscanHelper,
            $this->minItemsPerBucket,
            self::CLUSTER_RADIUS_METERS,
            $this->resolveWindowSeconds(),
        );

        return array_map(
            fn (array $bucket): ClusterDraft => $this->makeDraft($bucket),
            $buckets
        );
    }

    /** @param list<Media> $bucket */
    private function makeDraft(array $bucket): ClusterDraft
    {
        $centroid = $this->computeCentroid($bucket);
        $range    = $this->computeTimeRange($bucket);

        $params = [
            'time_range'     => $range,
            'window_bounds'  => $range,
            'members_count'  => count($bucket),
        ];

        $lat = $centroid['lat'] ?? null;
        $lon = $centroid['lon'] ?? null;
        if ($lat !== null && $lon !== null) {
            $params['centroid_cell7'] = GeoCell::fromPoint($lat, $lon, 7);
        }

        $params = $this->appendLocationMetadata($bucket, $params);

        $tagMetadata = $this->collectDominantTags($bucket);
        foreach ($tagMetadata as $key => $value) {
            $params[$key] = $value;
        }

        $qualityParams = $this->qualityAggregator->buildParams($bucket);
        foreach ($qualityParams as $qualityKey => $qualityValue) {
            if ($qualityValue !== null) {
                $params[$qualityKey] = $qualityValue;
            }
        }

        $peopleParams = $this->buildPeopleParams($bucket);
        $params       = [...$params, ...$peopleParams];

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
        return min($this->maxGapSeconds, self::MAX_WINDOW_SECONDS);
    }

}
