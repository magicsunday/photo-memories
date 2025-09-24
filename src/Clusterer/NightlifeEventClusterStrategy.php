<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractFilteredTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters evening/night sessions (20:00–04:00 local time) with time gap and spatial compactness.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 75])]
final class NightlifeEventClusterStrategy extends AbstractFilteredTimeGapClusterStrategy
{
    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $timeGapSeconds = 3 * 3600, // 3h
        private readonly float $radiusMeters = 300.0,
        int $minItems = 5
    ) {
        parent::__construct($timezone, $timeGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'nightlife_event';
    }

    protected function passesContextFilters(Media $media, DateTimeImmutable $local): bool
    {
        $hour = (int) $local->format('G');

        return $hour >= 20 || $hour <= 4;
    }

    protected function sessionRadiusMeters(): ?float
    {
        return $this->radiusMeters;
    }
}
