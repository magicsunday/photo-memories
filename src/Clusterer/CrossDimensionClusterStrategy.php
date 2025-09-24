<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\TimeGapSplitterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters items that are both temporally and spatially close.
 * Sliding-session approach with time gap and radius constraints.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 68])]
final class CrossDimensionClusterStrategy implements ClusterStrategyInterface
{
    use TimeGapSplitterTrait;

    public function __construct(
        private readonly int $timeGapSeconds = 2 * 3600,   // 2h
        private readonly float $radiusMeters = 150.0,      // 150 m
        private readonly int $minItems = 6
    ) {
    }

    public function name(): string
    {
        return 'cross_dimension';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        // Filter: need time; prefer GPS (we allow some without GPS as long as cluster centroid is stable)
        $withTime = \array_values(\array_filter(
            $items,
            static fn (Media $m): bool => $m->getTakenAt() instanceof DateTimeImmutable
        ));

        if (\count($withTime) < $this->minItems) {
            return [];
        }

        \usort($withTime, static function (Media $a, Media $b): int {
            return $a->getTakenAt() <=> $b->getTakenAt();
        });

        $sessions = $this->splitIntoTimeGapSessions($withTime, $this->timeGapSeconds, $this->minItems);

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
