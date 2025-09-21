<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds one macro cluster per year if enough items exist.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 60])]
final class YearInReviewClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly int $minItems = 150,
        private readonly int $minDistinctMonths = 5
    ) {
    }

    public function name(): string
    {
        return 'year_in_review';
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
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $y = (int) $t->format('Y');
            $byYear[$y] ??= [];
            $byYear[$y][] = $m;
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($byYear as $year => $list) {
            if (\count($list) < $this->minItems) {
                continue;
            }
            /** @var array<int,bool> $months */
            $months = [];
            foreach ($list as $m) {
                $months[(int) $m->getTakenAt()->format('n')] = true;
            }
            if (\count($months) < $this->minDistinctMonths) {
                continue;
            }

            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'year'       => $year,
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }
}
