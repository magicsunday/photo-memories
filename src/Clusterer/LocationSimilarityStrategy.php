<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

final class LocationSimilarityStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    public function __construct(
        private readonly LocationHelper $locHelper,
        private readonly float $radiusMeters = 150.0,
        private readonly int $minItems = 5,
        private readonly int $maxSpanHours = 24,
    ) {
        if ($this->radiusMeters <= 0.0) {
            throw new \InvalidArgumentException('radiusMeters must be > 0.');
        }
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
        }
        if ($this->maxSpanHours < 0) {
            throw new \InvalidArgumentException('maxSpanHours must be >= 0.');
        }
    }

    public function name(): string
    {
        return 'location_similarity';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var array<string, list<Media>> $byLocality */
        $byLocality = [];
        /** @var list<Media> $noLocality */
        $noLocality = [];

        foreach ($items as $m) {
            $key = $this->locHelper->localityKeyForMedia($m);
            if ($key !== null) {
                $byLocality[$key] = $byLocality[$key] ?? [];
                $byLocality[$key][] = $m;
            } else {
                $noLocality[] = $m;
            }
        }

        /** @var array<string, list<Media>> $eligibleLocalities */
        $eligibleLocalities = \array_filter(
            $byLocality,
            fn (array $group): bool => \count($group) >= $this->minItems
        );

        $drafts = [];

        foreach ($eligibleLocalities as $key => $group) {
            $label = $this->locHelper->majorityLabel($group);
            $params = [
                'place_key'  => $key,
                'time_range' => $this->computeTimeRange($group),
            ];
            if ($label !== null) {
                $params['place'] = $label;
            }

            $poi = $this->locHelper->majorityPoiContext($group);
            if ($poi !== null) {
                $params['poi_label'] = $poi['label'];
                if ($poi['categoryKey'] !== null) {
                    $params['poi_category_key'] = $poi['categoryKey'];
                }
                if ($poi['categoryValue'] !== null) {
                    $params['poi_category_value'] = $poi['categoryValue'];
                }
                if ($poi['tags'] !== []) {
                    $params['poi_tags'] = $poi['tags'];
                }
            }

            $drafts[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: $this->computeCentroid($group),
                members: $this->toMemberIds($group)
            );
        }

        // Fallback: räumlich + Zeitfenster
        /** @var list<list<Media>> $spatialBuckets */
        $spatialBuckets = $this->spatialWindows($noLocality);
        /** @var list<list<Media>> $eligibleBuckets */
        $eligibleBuckets = \array_values(\array_filter(
            $spatialBuckets,
            fn (array $bucket): bool => \count($bucket) >= $this->minItems
        ));

        foreach ($eligibleBuckets as $bucket) {
            $params = [
                'time_range' => $this->computeTimeRange($bucket),
            ];
            $drafts[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: $this->computeCentroid($bucket),
                members: $this->toMemberIds($bucket)
            );
        }

        return $drafts;
    }

    /** @param list<Media> $items @return list<list<Media>> */
    private function spatialWindows(array $items): array
    {
        $gps = \array_values(\array_filter(
            $items,
            static fn(Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null
        ));

        \usort(
            $gps,
            static fn(Media $a, Media $b): int =>
                ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        $out = [];
        /** @var list<Media> $bucket */
        $bucket = [];
        $start = null;

        foreach ($gps as $m) {
            $ts = $m->getTakenAt()?->getTimestamp() ?? 0;

            if ($bucket === []) {
                $bucket = [$m];
                $start  = $ts;
                continue;
            }

            $anchor = $bucket[0];
            $dist = MediaMath::haversineDistanceInMeters(
                (float) $anchor->getGpsLat(),
                (float) $anchor->getGpsLon(),
                (float) $m->getGpsLat(),
                (float) $m->getGpsLon()
            );
            $spanOk = $start !== null ? ($ts - $start) <= $this->maxSpanHours * 3600 : true;

            if ($dist <= $this->radiusMeters && $spanOk) {
                $bucket[] = $m;
            } else {
                if (\count($bucket) > 0) {
                    $out[] = $bucket;
                }
                $bucket = [$m];
                $start  = $ts;
            }
        }

        if ($bucket !== []) {
            $out[] = $bucket;
        }

        return $out;
    }
}
