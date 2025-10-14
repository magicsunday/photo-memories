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
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function array_map;
use function usort;

/**
 * Class TimeSimilarityStrategy.
 */
final readonly class TimeSimilarityStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use MediaFilterTrait;
    use ProgressAwareClusterTrait;

    private LocationHelper $locationHelper;

    private ClusterQualityAggregator $qualityAggregator;

    public function __construct(
        LocationHelper $locHelper,
        private int $maxGapSeconds = 21600,
        private int $minItemsPerBucket = 5,
        ?ClusterQualityAggregator $qualityAggregator = null,
    ) {
        if ($this->maxGapSeconds < 1) {
            throw new InvalidArgumentException('maxGapSeconds must be >= 1.');
        }

        if ($this->minItemsPerBucket < 1) {
            throw new InvalidArgumentException('minItemsPerBucket must be >= 1.');
        }

        $this->locationHelper    = $locHelper;
        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
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

        usort(
            $withTs,
            static fn (Media $a, Media $b): int => ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<list<Media>> $buckets */
        $buckets = [];
        /** @var list<Media> $bucket */
        $bucket  = [];
        $prevTs  = null;
        $prevKey = null;

        foreach ($withTs as $m) {
            $ts  = $m->getTakenAt()?->getTimestamp() ?? 0;
            $key = $this->locationHelper->localityKeyForMedia($m);

            $split = false;
            if ($prevTs !== null && ($ts - $prevTs) > $this->maxGapSeconds) {
                $split = true;
            }

            if ($prevKey !== null && $key !== null && $key !== $prevKey) {
                $split = true;
            }

            if ($split && $bucket !== []) {
                $buckets[] = $bucket;
                $bucket    = [];
            }

            $bucket[] = $m;
            $prevTs   = $ts;
            $prevKey  = $key ?? $prevKey;
        }

        if ($bucket !== []) {
            $buckets[] = $bucket;
        }

        $eligible = $this->filterListsByMinItems($buckets, $this->minItemsPerBucket);

        return array_map(
            fn (array $list): ClusterDraft => $this->makeDraft($list),
            $eligible
        );
    }

    /** @param list<Media> $bucket */
    private function makeDraft(array $bucket): ClusterDraft
    {
        $params = [
            'time_range' => $this->computeTimeRange($bucket),
        ];

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
            centroid: $this->computeCentroid($bucket),
            members: $this->toMemberIds($bucket)
        );
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
