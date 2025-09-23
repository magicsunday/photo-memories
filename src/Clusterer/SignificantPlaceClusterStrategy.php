<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Support\AbstractGeoCellClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Aggregates recurring places using a coarse geogrid (lat/lon rounding).
 * Creates one cluster per significant place with enough distinct visit days.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 82])]
final class SignificantPlaceClusterStrategy extends AbstractGeoCellClusterStrategy
{
    public function __construct(
        float $gridDegrees = 0.01,
        private readonly int $minVisitDays = 3,
        private readonly int $minItemsTotal = 20
    ) {
        parent::__construct($gridDegrees);
    }

    public function name(): string
    {
        return 'significant_place';
    }

    protected function shouldConsider(Media $media): bool
    {
        return $media->getTakenAt() instanceof DateTimeImmutable;
    }

    protected function minMembersPerCell(): int
    {
        return $this->minItemsTotal;
    }

    /**
     * @param list<Media> $members
     * @return list<ClusterDraft>
     */
    protected function clustersForCell(string $cell, array $members): array
    {
        $visitDays = $this->uniqueDateParts($members, 'Y-m-d');

        if (\count($visitDays) < $this->minVisitDays) {
            return [];
        }

        return [
            $this->buildClusterDraft(
                $this->name(),
                $members,
                [
                    'grid_cell'  => $cell,
                    'visit_days' => \count($visitDays),
                ]
            ),
        ];
    }
}
