<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Groups items captured within a short time & small spatial window.
 * Typical for bursts/series shots.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 95])]
final class BurstClusterStrategy extends AbstractTimeGapClusterStrategy
{
    public function __construct(
        int $maxGapSeconds = 90,
        private readonly float $maxMoveMeters = 50.0,
        int $minItems = 3,
        string $timezone = 'UTC'
    ) {
        parent::__construct($timezone, $maxGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'burst';
    }

    protected function shouldSplitSession(Media $previous, Media $current, int $gapSeconds): bool
    {
        $lat1 = $previous->getGpsLat();
        $lon1 = $previous->getGpsLon();
        $lat2 = $current->getGpsLat();
        $lon2 = $current->getGpsLon();

        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
            return false;
        }

        $distance = MediaMath::haversineDistanceInMeters($lat1, $lon1, $lat2, $lon2);

        return $distance > $this->maxMoveMeters;
    }
}
