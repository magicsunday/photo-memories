<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Marks "travel days" by summing GPS path distance within the day.
 */
final class TransitTravelDayClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly float $minTravelKm = 60.0,
        private readonly int $minGpsSamples = 5
    ) {
        if ($this->minTravelKm <= 0.0) {
            throw new \InvalidArgumentException('minTravelKm must be > 0.');
        }
        if ($this->minGpsSamples < 1) {
            throw new \InvalidArgumentException('minGpsSamples must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'transit_travel_day';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            if ($m->getGpsLat() === null || $m->getGpsLon() === null) {
                continue;
            }
            $local = $t->setTimezone($tz);
            $key = $local->format('Y-m-d');
            $byDay[$key] ??= [];
            $byDay[$key][] = $m;
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($byDay as $day => $list) {
            if (\count($list) < $this->minGpsSamples) {
                continue;
            }
            \usort($list, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

            $distKm = 0.0;
            for ($i = 1, $n = \count($list); $i < $n; $i++) {
                $p = $list[$i - 1];
                $q = $list[$i];
                $distKm += MediaMath::haversineDistanceInMeters(
                        (float) $p->getGpsLat(),
                        (float) $p->getGpsLon(),
                        (float) $q->getGpsLat(),
                        (float) $q->getGpsLon()
                    ) / 1000.0;
            }

            if ($distKm < $this->minTravelKm) {
                continue;
            }

            $centroid = MediaMath::centroid($list);
            $time     = MediaMath::timeRange($list);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'distance_km' => $distKm,
                    'time_range'  => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $list)
            );
        }

        return $out;
    }
}
