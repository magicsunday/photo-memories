<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use InvalidArgumentException;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Aggregates recurring places using a coarse geogrid (lat/lon rounding).
 * Creates one cluster per significant place with enough distinct visit days.
 */
final readonly class SignificantPlaceClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private LocationHelper $locHelper,
        private float $gridDegrees = 0.01, // ~1.1 km in lat (varies with lon)
        private int $minVisitDays = 3,
        private int $minItemsTotal = 20
    ) {
        if ($this->gridDegrees <= 0.0) {
            throw new InvalidArgumentException('gridDegrees must be > 0.');
        }

        if ($this->minVisitDays < 1) {
            throw new InvalidArgumentException('minVisitDays must be >= 1.');
        }

        if ($this->minItemsTotal < 1) {
            throw new InvalidArgumentException('minItemsTotal must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'significant_place';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $timestampedGps */
        $timestampedGps = $this->filterTimestampedGpsItems($items);

        /** @var array<string, list<Media>> $byCell */
        $byCell = [];

        foreach ($timestampedGps as $m) {
            $lat = $m->getGpsLat();
            $lon = $m->getGpsLon();
            $t   = $m->getTakenAt();
            \assert($t instanceof DateTimeImmutable);
            $cell = $this->cellKey((float) $lat, (float) $lon);
            $byCell[$cell] ??= [];
            $byCell[$cell][] = $m;
        }

        /** @var array<string,int> $visitCounts */
        $visitCounts = [];
        $eligiblePlaces = $this->filterGroupsByMinItems($byCell, $this->minItemsTotal);

        $eligiblePlaces = $this->filterGroupsWithKeys(
            $eligiblePlaces,
            function (array $list, string $cell) use (&$visitCounts): bool {
                /** @var array<string,bool> $days */
                $days = [];
                foreach ($list as $m) {
                    $takenAt = $m->getTakenAt();
                    \assert($takenAt instanceof DateTimeImmutable);
                    $days[$takenAt->format('Y-m-d')] = true;
                }

                $count = \count($days);
                if ($count < $this->minVisitDays) {
                    return false;
                }

                $visitCounts[$cell] = $count;

                return true;
            }
        );

        if ($eligiblePlaces === []) {
            return [];
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligiblePlaces as $cell => $list) {
            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $params = [
                'grid_cell'   => $cell,
                'visit_days'  => $visitCounts[$cell] ?? 0,
                'time_range'  => $time,
            ];
            $label = $this->locHelper->majorityLabel($list);
            if ($label !== null) {
                $params['place'] = $label;
            }

            $poi = $this->locHelper->majorityPoiContext($list);
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

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }

    private function cellKey(float $lat, float $lon): string
    {
        $gd = $this->gridDegrees;
        $rlat = $gd * \floor($lat / $gd);
        $rlon = $gd * \floor($lon / $gd);
        return \sprintf('%.4f,%.4f', $rlat, $rlon);
    }
}
