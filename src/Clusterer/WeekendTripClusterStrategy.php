<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractConsecutiveRunClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\PlaceLabelHelperTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Detects short weekend trips (Fri afternoon to Sun/Monday),
 * sufficiently far from a configured home location.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 85])]
final class WeekendTripClusterStrategy extends AbstractConsecutiveRunClusterStrategy
{
    use PlaceLabelHelperTrait;

    private ?float $lastRunDistanceKm = null;

    public function __construct(
        private readonly LocationHelper $locHelper,
        #[Autowire(env: 'MEMORIES_HOME_LAT')] private readonly ?float $homeLat,
        #[Autowire(env: 'MEMORIES_HOME_LON')] private readonly ?float $homeLon,
        private readonly float $minAwayKm = 80.0,
        int $minNights = 1,
        string $timezone = 'Europe/Berlin',
        int $minItemsPerDay = 1,
        int $minItemsTotal = 3
    ) {
        parent::__construct($timezone, $minItemsPerDay, $minItemsTotal, $minNights);
    }

    public function name(): string
    {
        return 'weekend_trip';
    }

    protected function groupKey(Media $media, DateTimeImmutable $local): ?string
    {
        return $this->locHelper->localityKeyForMedia($media);
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     * @param array<string, list<Media>> $daysMap
     * @param list<Media> $members
     */
    protected function isRunValid(array $run, array $daysMap, int $nights, array $members, string $groupKey): bool
    {
        $this->lastRunDistanceKm = $this->distanceFromHomeKm($members);

        if ($this->lastRunDistanceKm !== null && $this->lastRunDistanceKm < $this->minAwayKm) {
            return false;
        }

        return true;
    }

    /**
     * @param array{days:list<string>, items:list<Media>} $run
     * @param array<string, list<Media>> $daysMap
     * @param list<Media> $members
     * @return array<string, mixed>
     */
    protected function runParams(array $run, array $daysMap, int $nights, array $members, string $groupKey): array
    {
        $params = $this->withMajorityPlace($members, ['nights' => $nights]);
        $distance = $this->lastRunDistanceKm ?? $this->distanceFromHomeKm($members);
        if ($distance !== null) {
            $params['distance_km'] = $distance;
        }

        return $params;
    }

    /**
     * @param list<Media> $members
     */
    private function distanceFromHomeKm(array $members): ?float
    {
        if ($this->homeLat === null || $this->homeLon === null) {
            return null;
        }

        foreach ($members as $media) {
            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();

            if ($lat === null || $lon === null) {
                continue;
            }

            return MediaMath::haversineDistanceInMeters(
                (float) $this->homeLat,
                (float) $this->homeLon,
                (float) $lat,
                (float) $lon
            ) / 1000.0;
        }

        return null;
    }
}
