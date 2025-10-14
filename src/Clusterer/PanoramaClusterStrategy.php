<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_map;
use function count;
use function usort;

/**
 * Clusters panorama photos (very wide aspect ratio) into time sessions.
 */
final readonly class PanoramaClusterStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use MediaFilterTrait;
    use ProgressAwareClusterTrait;

    public function __construct(
        private float $minAspect = 2.4,     // width / height threshold
        private int $sessionGapSeconds = 3 * 3600,
        private int $minItemsPerRun = 3,
    ) {
        if ($this->minAspect <= 0.0) {
            throw new InvalidArgumentException('minAspect must be > 0.');
        }

        if ($this->sessionGapSeconds < 1) {
            throw new InvalidArgumentException('sessionGapSeconds must be >= 1.');
        }

        if ($this->minItemsPerRun < 1) {
            throw new InvalidArgumentException('minItemsPerRun must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'panorama';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $cand */
        $cand = $this->filterTimestampedItemsBy(
            $items,
            function (Media $m): bool {
                $flag = $m->isPanorama();
                if ($flag === true) {
                    return true;
                }

                if ($flag === false) {
                    return false;
                }

                $w = $m->getWidth();
                $h = $m->getHeight();

                if ($w === null || $h === null || $w <= 0 || $h <= 0) {
                    return false;
                }

                if ($w <= $h) {
                    return false;
                }

                $ratio = (float) $w / (float) $h;

                return $ratio >= $this->minAspect;
            }
        );
        if (count($cand) < $this->minItemsPerRun) {
            return [];
        }

        usort($cand, static fn (Media $a, Media $b): int => ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<Media> $buf */
        $buf  = [];
        $last = null;

        $flush = function () use (&$buf, &$out): void {
            if (count($buf) < $this->minItemsPerRun) {
                $buf = [];

                return;
            }

            $gps      = $this->filterGpsItems($buf);
            $centroid = $gps !== [] ? MediaMath::centroid($gps) : ['lat' => 0.0, 'lon' => 0.0];
            $time     = MediaMath::timeRange($buf);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $buf)
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
            $last  = $ts;
        }

        $flush();

        return $out;
    }
    /**
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void $update
     *
     * @return list<ClusterDraft>
     */
    public function clusterWithProgress(array $items, callable $update): array
    {
        return $this->runWithDefaultProgress($items, $update, fn (array $payload): array => $this->cluster($payload));
    }

}
