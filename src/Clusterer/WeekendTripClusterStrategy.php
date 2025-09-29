<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function count;
use function max;
use function usort;

/**
 * Detects short weekend trips (Fri afternoon to Sun/Monday),
 * sufficiently far from a configured home location.
 */
final readonly class WeekendTripClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;
    use MediaFilterTrait;

    public function __construct(
        private LocationHelper $locHelper,
        #[Autowire(env: 'MEMORIES_HOME_LAT')]
        private ?float $homeLat,
        #[Autowire(env: 'MEMORIES_HOME_LON')]
        private ?float $homeLon,
        private float $minAwayKm = 80.0,
        private int $minNights = 1,
        // Minimum media items per trip bucket before we analyse nights and distance.
        private int $minItemsPerTrip = 3,
    ) {
        if ($this->minAwayKm <= 0.0) {
            throw new InvalidArgumentException('minAwayKm must be > 0.');
        }

        if ($this->minNights < 0) {
            throw new InvalidArgumentException('minNights must be >= 0.');
        }

        if ($this->minItemsPerTrip < 1) {
            throw new InvalidArgumentException('minItemsPerTrip must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'weekend_trip';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $withTs = $this->filterTimestampedItems($items);

        usort(
            $withTs,
            static fn (Media $a, Media $b): int => ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<list<Media>> $buckets */
        $buckets = [];
        /** @var list<Media> $bucket */
        $bucket = [];

        foreach ($withTs as $m) {
            if (count($bucket) === 0) {
                $bucket[] = $m;
                continue;
            }

            if ($this->locHelper->sameLocality($bucket[0], $m)) {
                $bucket[] = $m;
                continue;
            }

            $buckets[] = $bucket;
            $bucket    = [$m];
        }

        if ($bucket !== []) {
            $buckets[] = $bucket;
        }

        $drafts = [];
        $countBuckets = count($buckets);
        for ($i = 0; $i < $countBuckets; $i++) {
            $trip = $buckets[$i];
            if (count($trip) < $this->minItemsPerTrip) {
                continue;
            }

            $prev = $i > 0 ? $buckets[$i - 1] : null;
            $next = $i + 1 < $countBuckets ? $buckets[$i + 1] : null;

            if (!$this->isBracketedByDifferentLocality($trip, $prev, $next)) {
                continue;
            }

            $d = $this->makeTripDraftOrNull($trip);
            if ($d instanceof ClusterDraft) {
                $drafts[] = $d;
            }
        }

        return $drafts;
    }

    /**
     * @param list<Media>      $bucket
     * @param list<Media>|null $previous
     * @param list<Media>|null $next
     */
    private function isBracketedByDifferentLocality(array $bucket, ?array $previous, ?array $next): bool
    {
        if ($previous === null || $next === null) {
            return false;
        }

        $first = $bucket[0] ?? null;
        $prevFirst = $previous[0] ?? null;
        $nextFirst = $next[0] ?? null;

        if (!$first instanceof Media || !$prevFirst instanceof Media || !$nextFirst instanceof Media) {
            return false;
        }

        if ($this->locHelper->sameLocality($first, $prevFirst)) {
            return false;
        }

        if ($this->locHelper->sameLocality($first, $nextFirst)) {
            return false;
        }

        return true;
    }

    /** @param list<Media> $bucket */
    private function makeTripDraftOrNull(array $bucket): ?ClusterDraft
    {
        $nights = $this->estimateNights($bucket);
        if ($nights < $this->minNights) {
            return null;
        }

        // weit genug von "Home" weg?
        $distanceKm = $this->distanceFromHomeKm($bucket);
        if ($distanceKm !== null && $distanceKm < $this->minAwayKm) {
            return null;
        }

        $label  = $this->locHelper->majorityLabel($bucket) ?? 'Ausflug';
        $params = [
            'place'      => $label,
            'nights'     => $nights,
            'time_range' => $this->computeTimeRange($bucket),
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

        $unique = count($days);

        return $unique > 0 ? max(0, $unique - 1) : 0;
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
