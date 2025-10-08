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
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use function count;
use function usort;

/**
 * Portrait-oriented photos grouped into time sessions (no face detection).
 */
final readonly class PortraitOrientationClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;

    private ClusterQualityAggregator $qualityAggregator;

    public function __construct(
        private LocationHelper $locationHelper,
        private float $minPortraitRatio = 1.2, // height / width
        private int $sessionGapSeconds = 2 * 3600,
        private int $minItemsPerRun = 4,
        ?ClusterQualityAggregator $qualityAggregator = null,
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

        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
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

                $orientation = $m->isPortrait();

                if ($orientation === true) {
                    return true;
                }

                if ($orientation === false) {
                    return false;
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
            $centroid     = $this->computeCentroid($run);
            $timeRange    = $this->computeTimeRange($run);
            $peopleParams = $this->buildPeopleParams($run);

            $params = ['time_range' => $timeRange, ...$peopleParams];

            $tags = $this->collectDominantTags($run);
            if ($tags !== []) {
                $params = [...$params, ...$tags];
            }

            $params = $this->appendLocationMetadata($run, $params);

            $qualityParams = $this->qualityAggregator->buildParams($run);
            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: $this->toMemberIds($run)
            );
        }

        return $out;
    }
}
