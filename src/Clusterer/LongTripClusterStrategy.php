<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractConsecutiveRunClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Detects multi-night trips away from home based on per-day centroids and distance threshold.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 90])]
final class LongTripClusterStrategy extends AbstractConsecutiveRunClusterStrategy
{
    public function __construct(
        /** Home base; if null, strategy is effectively disabled. */
        private readonly ?float $homeLat = null,
        private readonly ?float $homeLon = null,
        private readonly float $minAwayKm = 150.0,
        private readonly int $minNights = 3,
        string $timezone = 'Europe/Berlin',
        int $minItemsPerDay = 3
    ) {
        parent::__construct($timezone, $minItemsPerDay, 0, $minNights);
    }

    public function name(): string
    {
        return 'long_trip';
    }

    protected function isEnabled(): bool
    {
        return $this->homeLat !== null && $this->homeLon !== null;
    }

    /**
     * @param list<Media> $items
     */
    protected function isDayEligible(string $day, array $items): bool
    {
        if (!parent::isDayEligible($day, $items)) {
            return false;
        }

        $gps = \array_values(\array_filter(
            $items,
            static fn (Media $media): bool => $media->getGpsLat() !== null && $media->getGpsLon() !== null
        ));

        if ($gps === []) {
            return false;
        }

        $centroid = MediaMath::centroid($gps);
        $distanceKm = MediaMath::haversineDistanceInMeters(
            (float) $centroid['lat'],
            (float) $centroid['lon'],
            (float) $this->homeLat,
            (float) $this->homeLon
        ) / 1000.0;

        return $distanceKm >= $this->minAwayKm;
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     * @param array<string, list<Media>> $daysMap
     * @param list<Media> $members
     * @return array<string, mixed>
     */
    protected function runParams(array $run, array $daysMap, int $nights, array $members): array
    {
        return [
            'nights' => $nights,
            'distance_km' => $this->minAwayKm,
        ];
    }
}
