<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Portrait-oriented photos grouped into time sessions (no face detection).
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 45])]
final class PortraitOrientationClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly float $minPortraitRatio = 1.2, // height / width
        private readonly int $sessionGapSeconds = 2 * 3600,
        private readonly int $minItems = 4
    ) {
    }

    public function name(): string
    {
        return 'portrait_orientation';
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
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($w === null || $h === null || $w <= 0 || $h <= 0 || $ts === null) {
                continue;
            }
            if ($h <= $w) {
                continue;
            }
            $ratio = (float)$h / (float)$w;
            if ($ratio >= $this->minPortraitRatio) {
                $cand[] = $m;
            }
        }

        if (\count($cand) < $this->minItems) {
            return [];
        }

        \usort($cand, static fn(Media $a, Media $b): int =>
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
            $centroid = MediaMath::centroid($buf);
            $time     = MediaMath::timeRange($buf);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float)$centroid['lat'], 'lon' => (float)$centroid['lon']],
                members: \array_map(static fn(Media $m): int => $m->getId(), $buf)
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
