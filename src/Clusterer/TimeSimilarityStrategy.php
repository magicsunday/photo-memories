<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

final class TimeSimilarityStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    public function __construct(
        private readonly LocationHelper $locHelper,
        private readonly int $maxGapSeconds = 21600,
        private readonly int $minItems = 5,
    ) {
        if ($this->maxGapSeconds < 1) {
            throw new \InvalidArgumentException('maxGapSeconds must be >= 1.');
        }
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'time_similarity';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $withTs = \array_values(\array_filter(
            $items,
            static fn(Media $m): bool => $m->getTakenAt() instanceof DateTimeImmutable
        ));

        \usort(
            $withTs,
            static fn(Media $a, Media $b): int =>
                ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        $drafts = [];
        /** @var list<Media> $bucket */
        $bucket = [];
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

            if ($split) {
                if (\count($bucket) >= $this->minItems) {
                    $drafts[] = $this->makeDraft($bucket);
                }
                $bucket = [];
            }

            $bucket[] = $m;
            $prevTs   = $ts;
            $prevKey  = $key ?? $prevKey;
        }

        if (\count($bucket) >= $this->minItems) {
            $drafts[] = $this->makeDraft($bucket);
        }

        return $drafts;
    }

    /** @param list<Media> $bucket */
    private function makeDraft(array $bucket): ClusterDraft
    {
        $label = $this->locHelper->majorityLabel($bucket);
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
