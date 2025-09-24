<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Groups items captured within a short time & small spatial window.
 * Typical for bursts/series shots.
 */
final class BurstClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly int $maxGapSeconds = 90,
        private readonly float $maxMoveMeters = 50.0,
        private readonly int $minItems = 3
    ) {
    }

    public function name(): string
    {
        return 'burst';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $n = \count($items);
        if ($n < $this->minItems) {
            return [];
        }

        \usort($items, static fn (Media $a, Media $b): int =>
            ($a->getTakenAt() ?? new DateTimeImmutable('@0')) <=> ($b->getTakenAt() ?? new DateTimeImmutable('@0'))
        );

        $clusters = [];
        $current  = [];

        foreach ($items as $i => $media) {
            if ($i === 0) {
                $current[] = $media;
                continue;
            }

            $prev = $items[$i - 1];

            $timeOk = MediaMath::secondsBetween(
                    $media->getTakenAt() ?? new DateTimeImmutable('@0'),
                    $prev->getTakenAt() ?? new DateTimeImmutable('@0')
                ) <= $this->maxGapSeconds;

            $distOk = true;
            $lat1 = $prev->getGpsLat();
            $lon1 = $prev->getGpsLon();
            $lat2 = $media->getGpsLat();
            $lon2 = $media->getGpsLon();

            if ($lat1 !== null && $lon1 !== null && $lat2 !== null && $lon2 !== null) {
                $distOk = MediaMath::haversineDistanceInMeters($lat1, $lon1, $lat2, $lon2) <= $this->maxMoveMeters;
            }

            if ($timeOk && $distOk) {
                $current[] = $media;
                continue;
            }

            if (\count($current) >= $this->minItems) {
                $clusters[] = $this->makeDraft($current);
            }

            $current = [$media];
        }

        if (\count($current) >= $this->minItems) {
            $clusters[] = $this->makeDraft($current);
        }

        return $clusters;
    }

    /**
     * @param list<Media> $members
     */
    private function makeDraft(array $members): ClusterDraft
    {
        $centroid = MediaMath::centroid($members);

        return new ClusterDraft(
            algorithm: $this->name(),
            params: [
                'time_range' => MediaMath::timeRange($members),
            ],
            centroid: ['lat' => $centroid['lat'], 'lon' => $centroid['lon']],
            members: \array_map(static fn (Media $m): int => $m->getId(), $members)
        );
    }
}
