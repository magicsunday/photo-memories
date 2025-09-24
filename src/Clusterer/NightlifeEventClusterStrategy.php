<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\TimeGapSplitterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters evening/night sessions (20:00–04:00 local time) with time gap and spatial compactness.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 75])]
final class NightlifeEventClusterStrategy implements ClusterStrategyInterface
{
    use TimeGapSplitterTrait;

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

        $sessions = $this->splitIntoTimeGapSessions($night, $this->timeGapSeconds, $this->minItems);

        /** @var list<ClusterDraft> $out */
        $out = [];
        foreach ($sessions as $session) {
            $gps = \array_values(\array_filter($session, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            $centroid = $gps !== []
                ? MediaMath::centroid($gps)
                : ['lat' => 0.0, 'lon' => 0.0];

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
                $time = MediaMath::timeRange($session);
                $out[] = new ClusterDraft(
                    algorithm: $this->name(),
                    params: [
                        'time_range' => $time,
                    ],
                    centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                    members: \array_map(static fn (Media $m): int => $m->getId(), $session)
                );
            }
        }

        return $out;
    }
}
