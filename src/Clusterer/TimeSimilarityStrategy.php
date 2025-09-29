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
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function array_map;
use function usort;

final readonly class TimeSimilarityStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;
    use MediaFilterTrait;

    public function __construct(
        private LocationHelper $locHelper,
        private int $maxGapSeconds = 21600,
        private int $minItemsPerBucket = 5,
    ) {
        if ($this->maxGapSeconds < 1) {
            throw new InvalidArgumentException('maxGapSeconds must be >= 1.');
        }

        if ($this->minItemsPerBucket < 1) {
            throw new InvalidArgumentException('minItemsPerBucket must be >= 1.');
        }
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
            $key = $this->locHelper->localityKeyForMedia($m);

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
        $label  = $this->locHelper->majorityLabel($bucket);
        $params = [
            'time_range' => $this->computeTimeRange($bucket),
        ];
        if ($label !== null) {
            $params['place'] = $label;
        }

        return new ClusterDraft(
            algorithm: $this->name(),
            params: $params,
            centroid: $this->computeCentroid($bucket),
            members: $this->toMemberIds($bucket)
        );
    }
}
