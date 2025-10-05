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
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function array_map;
use function assert;
use function count;
use function usort;

/**
 * Marks "travel days" by summing GPS path distance within the day.
 */
final readonly class TransitTravelDayClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterLocationMetadataTrait;

    public function __construct(
        private LocalTimeHelper $localTimeHelper,
        private LocationHelper $locationHelper,
        private float $minTravelKm = 60.0,
        // Counts only media items that already contain GPS coordinates.
        private int $minItemsPerDay = 5,
    ) {
        if ($this->minTravelKm <= 0.0) {
            throw new InvalidArgumentException('minTravelKm must be > 0.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'transit_travel_day';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $timestampedGpsItems = $this->filterTimestampedGpsItems($items);

        if ($timestampedGpsItems === []) {
            return [];
        }

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($timestampedGpsItems as $m) {
            $local = $this->localTimeHelper->resolve($m);
            assert($local instanceof DateTimeImmutable);
            $key   = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        $eligibleDays = $this->filterGroupsByMinItems($byDay, $this->minItemsPerDay);

        /** @var array<string, float> $dayDistanceKm */
        $dayDistanceKm = [];
        $travelDays    = $this->filterGroupsWithKeys(
            $eligibleDays,
            function (array $list, string $day) use (&$dayDistanceKm): bool {
                $sorted = $list;
                usort($sorted, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

                $distKm = 0.0;
                for ($i = 1, $n = count($sorted); $i < $n; ++$i) {
                    $p = $sorted[$i - 1];
                    $q = $sorted[$i];
                    $distKm += MediaMath::haversineDistanceInMeters(
                        (float) $p->getGpsLat(),
                        (float) $p->getGpsLon(),
                        (float) $q->getGpsLat(),
                        (float) $q->getGpsLon()
                    ) / 1000.0;
                }

                if ($distKm < $this->minTravelKm) {
                    return false;
                }

                $dayDistanceKm[$day] = $distKm;

                return true;
            }
        );

        if ($travelDays === []) {
            return [];
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($travelDays as $day => $list) {
            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $params = $this->appendLocationMetadata($list, [
                'distance_km' => $dayDistanceKm[$day],
                'time_range'  => $time,
            ]);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }
}
