<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateInterval;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
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
final class WeekendTripClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    public function __construct(
        private readonly LocationHelper $locHelper,
        #[Autowire(env: 'MEMORIES_HOME_LAT')] private readonly ?float $homeLat,
        #[Autowire(env: 'MEMORIES_HOME_LON')] private readonly ?float $homeLon,
        private readonly float $minAwayKm = 80.0,
        private readonly int $minNights = 1,
    ) {
    }

    public function name(): string
    {
        return 'weekend_trip';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $withTs = \array_values(\array_filter(
            $items,
            static fn(Media $m): bool => $m->getTakenAt() instanceof DateTimeImmutable
        ));

        \usort(
            $withTs,
            static fn(Media $a, Media $b): int =>
                ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        $drafts = [];
        /** @var list<Media> $bucket */
        $bucket = [];

        foreach ($withTs as $m) {
            if ($bucket === []) {
                $bucket[] = $m;
                continue;
            }
            $same = $this->locHelper->sameLocality($bucket[0], $m);
            if ($same) {
                $bucket[] = $m;
            } else {
                $d = $this->makeTripDraftOrNull($bucket);
                if ($d !== null) {
                    $drafts[] = $d;
                }
                $bucket = [$m];
            }
        }
        if ($bucket !== []) {
            $d = $this->makeTripDraftOrNull($bucket);
            if ($d !== null) {
                $drafts[] = $d;
            }
        }

        return $drafts;
    }

    /** @param list<Media> $bucket */
    private function makeTripDraftOrNull(array $bucket): ?ClusterDraft
    {
        // mind. 3 Fotos, mind. minNights
        if (\count($bucket) < 3) {
            return null;
        }

        $nights = $this->estimateNights($bucket);
        if ($nights < $this->minNights) {
            return null;
        }

        // weit genug von "Home" weg?
        $distanceKm = $this->distanceFromHomeKm($bucket);
        if ($distanceKm !== null && $distanceKm < $this->minAwayKm) {
            return null;
        }

        $label = $this->locHelper->majorityLabel($bucket) ?? 'Ausflug';
        $params = [
            'place' => $label,
            'nights'      => $nights,
            'time_range'  => $this->computeTimeRange($bucket),
        ];
        if ($distanceKm !== null) {
            $params['distance_km'] = $distanceKm;
        }

        return new ClusterDraft(
            algorithm: $this->name(),
            params: $params,
            centroid: $this->computeCentroid($bucket),
            members: $this->toMemberIds($bucket)
        );
    }

    /** @param list<Media> $bucket */
    private function estimateNights(array $bucket): int
    {
        /** @var array<string,bool> $days */
        $days = [];
        foreach ($bucket as $m) {
            $t = $m->getTakenAt();
            if ($t instanceof DateTimeImmutable) {
                $days[$t->format('Y-m-d')] = true;
            }
        }
        $unique = \count($days);
        return $unique > 0 ? \max(0, $unique - 1) : 0;
    }

    /** @param list<Media> $bucket */
    private function distanceFromHomeKm(array $bucket): ?float
    {
        if ($this->homeLat === null || $this->homeLon === null) {
            return null;
        }
        foreach ($bucket as $m) {
            if ($m->getGpsLat() !== null && $m->getGpsLon() !== null) {
                $mtrs = MediaMath::haversineDistanceInMeters(
                    $this->homeLat,
                    $this->homeLon,
                    (float) $m->getGpsLat(),
                    (float) $m->getGpsLon()
                );
                return $mtrs / 1000.0;
            }
        }
        return null;
    }
}
