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
use MagicSunday\Memories\Utility\MediaMath;

use function array_values;
use function count;
use function usort;

/**
 * Class LocationSimilarityStrategy
 */
final readonly class LocationSimilarityStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;
    use MediaFilterTrait;

    public function __construct(
        private LocationHelper $locHelper,
        private float $radiusMeters = 150.0,
        private int $minItemsPerPlace = 5,
        private int $maxSpanHours = 24,
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
            $key = $this->locHelper->localityKeyForMedia($m);
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
            $label  = $this->locHelper->majorityLabel($group);
            $params = [
                'place_key'  => $key,
                'time_range' => $this->computeTimeRange($group),
            ];
            if ($label !== null) {
                $params['place'] = $label;
            }

            $tagMetadata = $this->collectDominantTags($group);
            foreach ($tagMetadata as $paramKey => $value) {
                $params[$paramKey] = $value;
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
        $eligibleBuckets = $this->filterListsByMinItems($spatialBuckets, $this->minItemsPerPlace);

        foreach ($eligibleBuckets as $bucket) {
            $params = [
                'time_range' => $this->computeTimeRange($bucket),
            ];

            $tagMetadata = $this->collectDominantTags($bucket);
            foreach ($tagMetadata as $paramKey => $value) {
                $params[$paramKey] = $value;
            }

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
        $gps = $this->filterTimestampedGpsItems($items);

        /** @var array<string, list<Media>> $byCell */
        $byCell = [];
        /** @var list<Media> $withoutCell */
        $withoutCell = [];

        foreach ($gps as $media) {
            $cell = $media->getGeoCell8();
            if ($cell !== null) {
                $byCell[$cell] ??= [];
                $byCell[$cell][] = $media;
                continue;
            }

            $withoutCell[] = $media;
        }

        /** @var list<list<Media>> $groups */
        $groups = array_values($byCell);
        if ($withoutCell !== []) {
            $groups[] = $withoutCell;
        }

        $out = [];

        foreach ($groups as $group) {
            usort(
                $group,
                static fn (Media $a, Media $b): int => ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
            );

            /** @var list<Media> $bucket */
            $bucket = [];
            $start  = null;

            foreach ($group as $media) {
                if ($media->getGpsLat() === null || $media->getGpsLon() === null) {
                    continue;
                }

                $ts = $media->getTakenAt()?->getTimestamp() ?? 0;

                if ($bucket === []) {
                    $bucket = [$media];
                    $start  = $ts;
                    continue;
                }

                $anchor    = $bucket[0];
                $anchorLat = $anchor->getGpsLat();
                $anchorLon = $anchor->getGpsLon();
                $mediaLat  = $media->getGpsLat();
                $mediaLon  = $media->getGpsLon();

                if ($anchorLat === null || $anchorLon === null || $mediaLat === null || $mediaLon === null) {
                    continue;
                }

                $dist = MediaMath::haversineDistanceInMeters(
                    $anchorLat,
                    $anchorLon,
                    $mediaLat,
                    $mediaLon
                );
                $spanOk = !($start !== null) || ($ts - $start) <= $this->maxSpanHours * 3600;

                if ($dist <= $this->radiusMeters && $spanOk) {
                    $bucket[] = $media;
                    continue;
                }

                if (count($bucket) > 0) {
                    $out[] = $bucket;
                }

                $bucket = [$media];
                $start  = $ts;
            }

            if ($bucket !== []) {
                $out[] = $bucket;
            }
        }

        return $out;
    }
}
