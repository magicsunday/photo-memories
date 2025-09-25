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

        $drafts = [];

        foreach ($byLocality as $key => $group) {
            if (\count($group) < $this->minItems) {
                continue;
            }
            $label = $this->locHelper->majorityLabel($group);
            $params = [
                'place_key'  => $key,
                'time_range' => $this->computeTimeRange($group),
            ];
            if ($label !== null) {
                $params['place'] = $label;
            }

            $drafts[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: $this->computeCentroid($group),
                members: $this->toMemberIds($group)
            );
        }

        // Fallback: räumlich + Zeitfenster
        foreach ($this->spatialWindows($noLocality) as $bucket) {
            if (\count($bucket) < $this->minItems) {
                continue;
            }
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
