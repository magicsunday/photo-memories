<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Groups items captured within a short time & small spatial window.
 * Typical for bursts/series shots.
 */
final class BurstClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private readonly int $maxGapSeconds = 90,
        private readonly float $maxMoveMeters = 50.0,
        // Minimum photos per burst run before emitting a memory.
        private readonly int $minItemsPerBurst = 3
    ) {
        if ($this->maxGapSeconds < 1) {
            throw new \InvalidArgumentException('maxGapSeconds must be >= 1.');
        }
        if ($this->maxMoveMeters < 0.0) {
            throw new \InvalidArgumentException('maxMoveMeters must be >= 0.');
        }
        if ($this->minItemsPerBurst < 1) {
            throw new \InvalidArgumentException('minItemsPerBurst must be >= 1.');
        }
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
        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        $n = \count($timestamped);
        if ($n < $this->minItemsPerBurst) {
            return [];
        }

        \usort(
            $timestamped,
            static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt()
        );

        /** @var list<list<Media>> $sessions */
        $sessions = [];
        /** @var list<Media> $current */
        $current  = [];

        foreach ($timestamped as $i => $media) {
            if ($i === 0) {
                $current[] = $media;
                continue;
            }

            $prev = $timestamped[$i - 1];

            $currTakenAt = $media->getTakenAt();
            $prevTakenAt = $prev->getTakenAt();
            \assert($currTakenAt instanceof DateTimeImmutable);
            \assert($prevTakenAt instanceof DateTimeImmutable);

            $timeOk = MediaMath::secondsBetween(
                    $currTakenAt,
                    $prevTakenAt
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

            $sessions[] = $current;
            $current     = [$media];
        }

        if ($current !== []) {
            $sessions[] = $current;
        }

        $eligible = $this->filterListsByMinItems($sessions, $this->minItemsPerBurst);

        return \array_map(
            fn (array $members): ClusterDraft => $this->makeDraft($members),
            $eligible
        );
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
