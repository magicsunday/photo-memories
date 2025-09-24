<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Marks "travel days" by summing GPS path distance within the day.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 87])]
final class TransitTravelDayClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly float $minTravelKm = 60.0,
        private readonly int $minGpsSamples = 5
    ) {
        parent::__construct($timezone);
    }

    public function name(): string
    {
        return 'transit_travel_day';
    }

    /**
     * @param list<Media> $members
     */
    protected function minimumGroupSize(string $key, array $members): int
    {
        return $this->minGpsSamples;
    }

    protected function localGroupKey(Media $media, DateTimeImmutable $local): ?string
    {
        if ($media->getGpsLat() === null || $media->getGpsLon() === null) {
            return null;
        }

        return $local->format('Y-m-d');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        \usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $distanceKm = 0.0;
        for ($i = 1, $n = \count($members); $i < $n; $i++) {
            $prev = $members[$i - 1];
            $curr = $members[$i];
            $distanceKm += MediaMath::haversineDistanceInMeters(
                (float) $prev->getGpsLat(),
                (float) $prev->getGpsLon(),
                (float) $curr->getGpsLat(),
                (float) $curr->getGpsLon()
            ) / 1000.0;
        }

        if ($distanceKm < $this->minTravelKm) {
            return null;
        }

        return [
            'distance_km' => $distanceKm,
        ];
    }
}
