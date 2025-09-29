<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Groups hiking/adventure sessions based on keywords; validates by traveled distance if GPS is available.
 */
final readonly class HikeAdventureClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private int $sessionGapSeconds = 3 * 3600,
        private float $minDistanceKm = 6.0, // require at least ~6km if GPS is present
        private int $minItemsPerRun = 6,
        private int $minItemsPerRunNoGps = 12 // stricter if no GPS available
    ) {
        if ($this->sessionGapSeconds < 1) {
            throw new InvalidArgumentException('sessionGapSeconds must be >= 1.');
        }

        if ($this->minDistanceKm <= 0.0) {
            throw new InvalidArgumentException('minDistanceKm must be > 0.');
        }

        if ($this->minItemsPerRun < 1) {
            throw new InvalidArgumentException('minItemsPerRun must be >= 1.');
        }

        if ($this->minItemsPerRunNoGps < 1) {
            throw new InvalidArgumentException('minItemsPerRunNoGps must be >= 1.');
        }
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
        $cand = $this->filterTimestampedItemsBy(
            $items,
            fn (Media $m): bool => $this->looksHike(\strtolower($m->getPath()))
        );

        if (\count($cand) < $this->minItemsPerRun) {
            return [];
        }

        \usort($cand, static fn (Media $a, Media $b): int =>
            ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<list<Media>> $runs */
        $runs = [];
        /** @var list<Media> $buf */
        $buf = [];
        $last = null;

        foreach ($cand as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts === null) {
                continue;
            }

            if ($last !== null && ($ts - $last) > $this->sessionGapSeconds && $buf !== []) {
                $runs[] = $buf;
                $buf = [];
            }

            $buf[] = $m;
            $last = $ts;
        }

        if ($buf !== []) {
            $runs[] = $buf;
        }

        $eligibleRuns = $this->filterListsByMinItems($runs, $this->minItemsPerRun);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleRuns as $run) {
            $withGps = $this->filterGpsItems($run);

            if ($withGps !== []) {
                \usort(
                    $withGps,
                    static fn (Media $a, Media $b): int =>
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
            } elseif (\count($run) < $this->minItemsPerRunNoGps) {
                // No GPS: require more items to reduce false positives
                continue;
            }

            $centroid = MediaMath::centroid($run);
            $time     = MediaMath::timeRange($run);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $run)
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
