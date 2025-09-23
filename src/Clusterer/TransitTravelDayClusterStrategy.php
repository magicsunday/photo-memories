<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\AbstractGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Marks "travel days" by summing GPS path distance within the day.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 87])]
final class TransitTravelDayClusterStrategy extends AbstractGroupedClusterStrategy
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly float $minTravelKm = 60.0,
        private readonly int $minGpsSamples = 5
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function name(): string
    {
        return 'transit_travel_day';
    }

    protected function groupKey(Media $media): ?string
    {
        $takenAt = $media->getTakenAt();
        $lat = $media->getGpsLat();
        $lon = $media->getGpsLon();

        if (!$takenAt instanceof DateTimeImmutable || $lat === null || $lon === null) {
            return null;
        }

        return $takenAt->setTimezone($this->timezone)->format('Y-m-d');
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minGpsSamples) {
            return null;
        }

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
