<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Aggregates panoramas across years; requires per-year minimum.
 */
final class PanoramaOverYearsClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly float $minAspect = 2.4,
        private readonly int $perYearMin = 3,
        private readonly int $minYears = 3,
        private readonly int $minItemsTotal = 15
    ) {
    }

    public function name(): string
    {
        return 'panorama_over_years';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var array<int, list<Media>> $byYear */
        $byYear = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            $w = $m->getWidth();
            $h = $m->getHeight();
            if ($t === null || $w === null || $h === null || $w <= 0 || $h <= 0 || $w <= $h) {
                continue;
            }
            $ratio = (float) $w / (float) $h;
            if ($ratio < $this->minAspect) {
                continue;
            }
            $y = (int) $t->format('Y');
            $byYear[$y] ??= [];
            $byYear[$y][] = $m;
        }

        /** @var array<int, list<Media>> $eligibleYears */
        $eligibleYears = \array_filter(
            $byYear,
            fn (array $list): bool => \count($list) >= $this->perYearMin
        );

        if ($eligibleYears === []) {
            return [];
        }

        /** @var list<Media> $picked */
        $picked = [];
        /** @var array<int,bool> $years */
        $years = [];

        foreach ($eligibleYears as $y => $list) {
            foreach ($list as $m) {
                $picked[] = $m;
            }
            $years[$y] = true;
        }

        if (\count($years) < $this->minYears || \count($picked) < $this->minItemsTotal) {
            return [];
        }

        \usort($picked, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());
        $centroid = MediaMath::centroid($picked);
        $time     = MediaMath::timeRange($picked);

        return [
            new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'years'      => \array_values(\array_keys($years)),
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $picked)
            ),
        ];
    }
}
