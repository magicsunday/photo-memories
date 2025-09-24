<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractConsecutiveRunClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Detects multi-day road trips based on daily traveled distance (from GPS track).
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 88])]
final class RoadTripClusterStrategy extends AbstractConsecutiveRunClusterStrategy
{
    public function __construct(
        private readonly float $minDailyKm = 120.0,
        private readonly int $minGpsSamplesPerDay = 8,
        int $minNights = 3,       // => at least 4 days
        int $minItemsTotal = 40,
        string $timezone = 'Europe/Berlin'
    ) {
        parent::__construct($timezone, $minGpsSamplesPerDay, $minItemsTotal, $minNights);
    }

    public function name(): string
    {
        return 'road_trip';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        return $media->getGpsLat() !== null && $media->getGpsLon() !== null;
    }

    /**
     * @param list<Media> $items
     */
    protected function isDayEligible(string $day, array $items, string $groupKey): bool
    {
        if (!parent::isDayEligible($day, $items, $groupKey)) {
            return false;
        }

        \usort($items, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $km = 0.0;
        for ($i = 1, $n = \count($items); $i < $n; $i++) {
            $prev = $items[$i - 1];
            $curr = $items[$i];
            $km += MediaMath::haversineDistanceInMeters(
                (float) $prev->getGpsLat(),
                (float) $prev->getGpsLon(),
                (float) $curr->getGpsLat(),
                (float) $curr->getGpsLon()
            ) / 1000.0;
        }

        return $km >= $this->minDailyKm;
    }
}
