<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_map;
use function count;
use function usort;

/**
 * Portrait-oriented photos grouped into time sessions (no face detection).
 */
final readonly class PortraitOrientationClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private float $minPortraitRatio = 1.2, // height / width
        private int $sessionGapSeconds = 2 * 3600,
        private int $minItemsPerRun = 4,
    ) {
        if ($this->minPortraitRatio <= 0.0) {
            throw new InvalidArgumentException('minPortraitRatio must be > 0.');
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
        return 'portrait_orientation';
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
                if ($m->hasFaces() === false) {
                    $persons = $m->getPersons();

                    if ($persons === null || count($persons) === 0) {
                        return false;
                    }
                }

                $w = $m->getWidth();
                $h = $m->getHeight();

                if ($w === null || $h === null || $w <= 0 || $h <= 0) {
                    return false;
                }

                if ($h <= $w) {
                    return false;
                }

                $ratio = (float) $h / (float) $w;

                return $ratio >= $this->minPortraitRatio;
            }
        );

        if (count($cand) < $this->minItemsPerRun) {
            return [];
        }

        usort($cand, static fn (Media $a, Media $b): int => ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<list<Media>> $runs */
        $runs = [];
        /** @var list<Media> $buf */
        $buf  = [];
        $last = null;

        foreach ($cand as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts === null) {
                continue;
            }

            if ($last !== null && ($ts - $last) > $this->sessionGapSeconds && $buf !== []) {
                $runs[] = $buf;
                $buf    = [];
            }

            $buf[] = $m;
            $last  = $ts;
        }

        if ($buf !== []) {
            $runs[] = $buf;
        }

        $eligibleRuns = $this->filterListsByMinItems($runs, $this->minItemsPerRun);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleRuns as $run) {
            $centroid = MediaMath::centroid($run);
            $time     = MediaMath::timeRange($run);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $run)
            );
        }

        return $out;
    }
}
