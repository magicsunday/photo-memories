<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters items that are both temporally and spatially close.
 * Sliding-session approach with time gap and radius constraints.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 68])]
final class CrossDimensionClusterStrategy extends AbstractTimeGapClusterStrategy
{
    public function __construct(
        int $timeGapSeconds = 2 * 3600,   // 2h
        private readonly float $radiusMeters = 150.0,      // 150 m
        int $minItems = 6
    ) {
        parent::__construct('UTC', $timeGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'cross_dimension';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        return true;
    }

    /**
     * @param list<Media> $members
     */
    protected function isSessionValid(array $members): bool
    {
        return parent::isSessionValid($members)
            && $this->allWithinRadius($members, $this->radiusMeters);
    }
}
