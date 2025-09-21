<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters panorama photos (very wide aspect ratio) into time sessions.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 47])]
final class PanoramaClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly float $minAspect = 2.4,     // width / height threshold
        private readonly int $sessionGapSeconds = 3 * 3600,
        private readonly int $minItems = 3
    ) {
    }

    public function name(): string
    {
        return 'panorama';
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
            $w = $m->getWidth();
            $h = $m->getHeight();
            if ($w === null || $h === null || $w <= 0 || $h <= 0) {
                continue;
            }
            if ($w <= $h) {
                continue; // require landscape panoramas
            }
            $ratio = (float) $w / (float) $h;
            if ($ratio >= $this->minAspect) {
                $cand[] = $m;
            }
        }
        if (\count($cand) < $this->minItems) {
            return [];
        }

        \usort($cand, static fn (Media $a, Media $b): int =>
            ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<Media> $buf */
        $buf = [];
        $last = null;

        $flush = function () use (&$buf, &$out): void {
            if (\count($buf) < $this->minItems) {
                $buf = [];
                return;
            }
            $gps = \array_values(\array_filter($buf, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            $centroid = $gps !== [] ? MediaMath::centroid($gps) : ['lat' => 0.0, 'lon' => 0.0];
            $time = MediaMath::timeRange($buf);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $buf)
            );
            $buf = [];
        };

        foreach ($cand as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts === null) {
                continue;
            }
            if ($last !== null && ($ts - $last) > $this->sessionGapSeconds) {
                $flush();
            }
            $buf[] = $m;
            $last = $ts;
        }
        $flush();

        return $out;
    }
}
