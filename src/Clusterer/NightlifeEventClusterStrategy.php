<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Clusters evening/night sessions (20:00–04:00 local time) with time gap and spatial compactness.
 */
final class NightlifeEventClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $timeGapSeconds = 3 * 3600, // 3h
        private readonly float $radiusMeters = 300.0,
        private readonly int $minItems = 5
    ) {
    }

    public function name(): string
    {
        return 'nightlife_event';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        $night = \array_values(\array_filter($items, function (Media $m) use ($tz): bool {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                return false;
            }
            $local = $t->setTimezone($tz);
            $h = (int) $local->format('G'); // 0..23
            return ($h >= 20) || ($h <= 4);
        }));

        if (\count($night) < $this->minItems) {
            return [];
        }

        \usort($night, static function (Media $a, Media $b): int {
            return $a->getTakenAt() <=> $b->getTakenAt();
        });

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<Media> $buf */
        $buf = [];
        $lastTs = null;

        $flush = function () use (&$buf, &$out): void {
            if (\count($buf) < $this->minItems) {
                $buf = [];
                return;
            }
            $gps = \array_values(\array_filter($buf, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            $centroid = $gps !== []
                ? MediaMath::centroid($gps)
                : ['lat' => 0.0, 'lon' => 0.0];

            // require spatial compactness if GPS present
            $ok = true;
            foreach ($gps as $m) {
                $dist = MediaMath::haversineDistanceInMeters(
                    $centroid['lat'],
                    $centroid['lon'],
                    (float) $m->getGpsLat(),
                    (float) $m->getGpsLon()
                );

                if ($dist > $this->radiusMeters) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $time = MediaMath::timeRange($buf);
                $out[] = new ClusterDraft(
                    algorithm: 'nightlife_event',
                    params: [
                        'time_range' => $time,
                    ],
                    centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                    members: \array_map(static fn (Media $m): int => $m->getId(), $buf)
                );
            }
            $buf = [];
        };

        foreach ($night as $m) {
            $ts = (int) $m->getTakenAt()->getTimestamp();
            if ($lastTs !== null && ($ts - $lastTs) > $this->timeGapSeconds) {
                $flush();
            }
            $buf[] = $m;
            $lastTs = $ts;
        }
        $flush();

        return $out;
    }
}
