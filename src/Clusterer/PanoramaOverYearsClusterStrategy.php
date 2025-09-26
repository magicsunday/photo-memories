<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Aggregates panoramas across years; requires per-year minimum.
 */
final class PanoramaOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private readonly float $minAspect = 2.4,
        // Minimum panoramas that must exist within each individual year.
        private readonly int $minItemsPerYear = 3,
        private readonly int $minYears = 3,
        private readonly int $minItemsTotal = 15
    ) {
        if ($this->minAspect <= 0.0) {
            throw new \InvalidArgumentException('minAspect must be > 0.');
        }
        if ($this->minItemsPerYear < 1) {
            throw new \InvalidArgumentException('minItemsPerYear must be >= 1.');
        }
        if ($this->minYears < 1) {
            throw new \InvalidArgumentException('minYears must be >= 1.');
        }
        if ($this->minItemsTotal < 1) {
            throw new \InvalidArgumentException('minItemsTotal must be >= 1.');
        }
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

        $panoramaItems = $this->filterTimestampedItemsBy(
            $items,
            function (Media $m): bool {
                $w = $m->getWidth();
                $h = $m->getHeight();

                if ($w === null || $h === null || $w <= 0 || $h <= 0 || $w <= $h) {
                    return false;
                }

                $ratio = (float) $w / (float) $h;

                return $ratio >= $this->minAspect;
            }
        );

        foreach ($panoramaItems as $m) {
            $t = $m->getTakenAt();
            \assert($t instanceof \DateTimeImmutable);
            $y = (int) $t->format('Y');
            $byYear[$y] ??= [];
            $byYear[$y][] = $m;
        }

        /** @var array<int, list<Media>> $eligibleYears */
        $eligibleYears = $this->filterGroupsByMinItems($byYear, $this->minItemsPerYear);

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
