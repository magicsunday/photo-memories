<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Heuristic pet moments based on path keywords; grouped into time sessions.
 */
final readonly class PetMomentsClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private int $sessionGapSeconds = 2 * 3600,
        private int $minItemsPerRun = 6
    ) {
        if ($this->sessionGapSeconds < 1) {
            throw new InvalidArgumentException('sessionGapSeconds must be >= 1.');
        }

        if ($this->minItemsPerRun < 1) {
            throw new InvalidArgumentException('minItemsPerRun must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'pet_moments';
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
            fn (Media $m): bool => $this->looksLikePet(\strtolower($m->getPath()))
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
            $gps = $this->filterGpsItems($run);
            $centroid = $gps !== [] ? MediaMath::centroid($gps) : ['lat' => 0.0, 'lon' => 0.0];
            $time = MediaMath::timeRange($run);

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

    private function looksLikePet(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = [
            'dog', 'dogs', 'hund', 'hunde', 'welpe', 'puppy',
            'cat', 'cats', 'katze', 'kater', 'kitten',
            'hamster', 'kaninchen', 'bunny', 'rabbit',
            'meerschwein', 'guinea', 'pony', 'pferd',
            'haustier', 'pet', 'tierpark', 'zoo',
        ];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }

        return false;
    }
}
