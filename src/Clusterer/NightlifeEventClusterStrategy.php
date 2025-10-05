<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_map;
use function assert;
use function count;
use function usort;

/**
 * Clusters evening/night sessions (20:00–04:00 local time) with time gap and spatial compactness.
 */
final readonly class NightlifeEventClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private LocalTimeHelper $localTimeHelper,
        private int $timeGapSeconds = 3 * 3600, // 3h
        private float $radiusMeters = 300.0,
        private int $minItemsPerRun = 5,
    ) {
        if ($this->timeGapSeconds < 1) {
            throw new InvalidArgumentException('timeGapSeconds must be >= 1.');
        }

        if ($this->radiusMeters <= 0.0) {
            throw new InvalidArgumentException('radiusMeters must be > 0.');
        }

        if ($this->minItemsPerRun < 1) {
            throw new InvalidArgumentException('minItemsPerRun must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'nightlife_event';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $night = $this->filterTimestampedItemsBy(
            $items,
            function (Media $m): bool {
                $local = $this->localTimeHelper->resolve($m);
                assert($local instanceof DateTimeImmutable);
                $h     = (int) $local->format('G');

                return ($h >= 20) || ($h <= 4);
            }
        );

        if (count($night) < $this->minItemsPerRun) {
            return [];
        }

        usort($night, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        /** @var list<list<Media>> $runs */
        $runs = [];
        /** @var list<Media> $buf */
        $buf    = [];
        $lastTs = null;

        foreach ($night as $m) {
            $ts = (int) $m->getTakenAt()->getTimestamp();
            if ($lastTs !== null && ($ts - $lastTs) > $this->timeGapSeconds && $buf !== []) {
                $runs[] = $buf;
                $buf    = [];
            }

            $buf[]  = $m;
            $lastTs = $ts;
        }

        if ($buf !== []) {
            $runs[] = $buf;
        }

        $eligibleRuns = $this->filterListsByMinItems($runs, $this->minItemsPerRun);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleRuns as $run) {
            $gps      = $this->filterGpsItems($run);
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

            if (!$ok) {
                continue;
            }

            $time  = MediaMath::timeRange($run);
            $out[] = new ClusterDraft(
                algorithm: 'nightlife_event',
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
