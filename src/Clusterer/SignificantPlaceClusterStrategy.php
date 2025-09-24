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

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($byCell as $cell => $list) {
            if (\count($list) < $this->minItemsTotal) {
                continue;
            }

            /** @var array<string,bool> $days */
            $days = [];
            foreach ($list as $m) {
                $days[$m->getTakenAt()->format('Y-m-d')] = true;
            }
            if (\count($days) < $this->minVisitDays) {
                continue;
            }

            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $params = [
                'grid_cell'   => $cell,
                'visit_days'  => \count($days),
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
