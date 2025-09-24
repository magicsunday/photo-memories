<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\TimeGapSplitterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Groups hiking/adventure sessions based on keywords; validates by traveled distance if GPS is available.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 74])]
final class HikeAdventureClusterStrategy implements ClusterStrategyInterface
{
    use TimeGapSplitterTrait;

    public function __construct(
        private readonly int $sessionGapSeconds = 3 * 3600,
        private readonly float $minDistanceKm = 6.0, // require at least ~6km if GPS is present
        private readonly int $minItems = 6,
        private readonly int $minItemsNoGps = 12 // stricter if no GPS available
    ) {
    }

    public function name(): string
    {
        return 'hike_adventure';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $cand */
        $cand = [];
        foreach ($items as $m) {
            $t  = $m->getTakenAt();
            $pl = \strtolower($m->getPath());
            if ($t !== null && $this->looksHike($pl)) {
                $cand[] = $m;
            }
        }

        if (\count($cand) < $this->minItems) {
            return [];
        }

        \usort($cand, static fn (Media $a, Media $b): int =>
            ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        $sessions = $this->splitIntoTimeGapSessions($cand, $this->sessionGapSeconds, $this->minItems);

        /** @var list<ClusterDraft> $out */
        $out = [];
        foreach ($sessions as $session) {
            $n = \count($session);
            $withGps = \array_values(\array_filter($session, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));

            if ($withGps !== []) {
                \usort($withGps, static fn (Media $a, Media $b): int =>
                    ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
                );
                $km = 0.0;
                for ($i = 1, $k = \count($withGps); $i < $k; $i++) {
                    $p = $withGps[$i - 1];
                    $q = $withGps[$i];
                    $km += MediaMath::haversineDistanceInMeters(
                            (float) $p->getGpsLat(),
                            (float) $p->getGpsLon(),
                            (float) $q->getGpsLat(),
                            (float) $q->getGpsLon()
                        ) / 1000.0;
                }
                if ($km < $this->minDistanceKm) {
                    continue;
                }
            } elseif ($n < $this->minItemsNoGps) {
                continue;
            }

            $centroid = MediaMath::centroid($session);
            $time     = MediaMath::timeRange($session);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $session)
            );
        }

        return $out;
    }

    private function looksHike(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = [
            'wander', 'wanderung', 'trail', 'hike', 'hiking', 'gipfel',
            'alpen', 'dolomiten', 'pass', 'berg', 'berge', 'klettersteig',
        ];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
