<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Aggregates recurring places using a coarse geogrid (lat/lon rounding).
 * Creates one cluster per significant place with enough distinct visit days.
 */
final class SignificantPlaceClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly LocationHelper $locHelper,
        private readonly float $gridDegrees = 0.01, // ~1.1 km in lat (varies with lon)
        private readonly int $minVisitDays = 3,
        private readonly int $minItemsTotal = 20
    ) {
        if ($this->gridDegrees <= 0.0) {
            throw new \InvalidArgumentException('gridDegrees must be > 0.');
        }
        if ($this->minVisitDays < 1) {
            throw new \InvalidArgumentException('minVisitDays must be >= 1.');
        }
        if ($this->minItemsTotal < 1) {
            throw new \InvalidArgumentException('minItemsTotal must be >= 1.');
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
        /** @var array<string, list<Media>> $byCell */
        $byCell = [];

        foreach ($items as $m) {
            $lat = $m->getGpsLat();
            $lon = $m->getGpsLon();
            $t   = $m->getTakenAt();
            if ($lat === null || $lon === null || !$t instanceof DateTimeImmutable) {
                continue;
            }
            $cell = $this->cellKey((float) $lat, (float) $lon);
            $byCell[$cell] ??= [];
            $byCell[$cell][] = $m;
        }

        /** @var array<string,int> $visitCounts */
        $visitCounts = [];
        $eligiblePlaces = \array_filter(
            $byCell,
            function (array $list, string $cell) use (&$visitCounts): bool {
                if (\count($list) < $this->minItemsTotal) {
                    return false;
                }

                /** @var array<string,bool> $days */
                $days = [];
                foreach ($list as $m) {
                    $days[$m->getTakenAt()->format('Y-m-d')] = true;
                }

                $count = \count($days);
                if ($count < $this->minVisitDays) {
                    return false;
                }

                $visitCounts[$cell] = $count;

                return true;
            },
            ARRAY_FILTER_USE_BOTH
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
