<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Aggregates panoramas across years; requires per-year minimum.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 46])]
final class PanoramaOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

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

        $picked = [];
        $years  = [];

        foreach ($byYear as $year => $list) {
            if (\count($list) < $this->perYearMin) {
                continue;
            }
            foreach ($list as $media) {
                $picked[] = $media;
            }
            $years[$year] = true;
        }

        return $this->buildOverYearsDrafts(
            $picked,
            $years,
            $this->minYears,
            $this->minItemsTotal,
            $this->name()
        );
    }
}
