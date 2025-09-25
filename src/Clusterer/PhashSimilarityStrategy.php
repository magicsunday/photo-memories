<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

final class PhashSimilarityStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    public function __construct(
        private readonly LocationHelper $locHelper,
        private readonly int $maxHamming = 6,
        private readonly int $minItems = 2,
    ) {
        if ($this->maxHamming < 0) {
            throw new \InvalidArgumentException('maxHamming must be >= 0.');
        }
        if ($this->minItems < 1) {
            throw new \InvalidArgumentException('minItems must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'phash_similarity';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        // 1) nur mit pHash
        $with = \array_values(\array_filter(
            $items,
            static fn(Media $m): bool => \is_string($m->getPhash()) && $m->getPhash() !== ''
        ));

        if ($with === []) {
            return [];
        }

        // 2) Buckets nach kurzer Präfix-Ähnlichkeit (4 Hex = 16 Bits); reduziert O(n^2)
        /** @var array<string, list<Media>> $buckets */
        $buckets = [];
        foreach ($with as $m) {
            $p = (string) $m->getPhash();
            $bucketKey = \substr($p, 0, 4);
            $buckets[$bucketKey] = $buckets[$bucketKey] ?? [];
            $buckets[$bucketKey][] = $m;
        }

        /** @var array<string, list<Media>> $eligibleBuckets */
        $eligibleBuckets = \array_filter(
            $buckets,
            fn (array $group): bool => \count($group) >= $this->minItems
        );

        // 3) Pro Bucket Komponenten nach Hamming-Distanz
        $drafts = [];
        foreach ($eligibleBuckets as $group) {
            foreach ($this->components($group) as $comp) {
                if (\count($comp) < $this->minItems) {
                    continue;
                }
                $params = [
                    'time_range' => $this->computeTimeRange($comp),
                ];
                $place = $this->locHelper->majorityLabel($comp);
                if ($place !== null) {
                    $params['place'] = $place;
                }

                $drafts[] = new ClusterDraft(
                    algorithm: $this->name(),
                    params: $params,
                    centroid: $this->computeCentroid($comp),
                    members: $this->toMemberIds($comp)
                );
            }
        }

        return $drafts;
    }

    /** @param list<Media> $items @return list<list<Media>> */
    private function components(array $items): array
    {
        $n = \count($items);
        if ($n <= 1) {
            return $n === 1 ? [[$items[0]]] : [];
        }

        // Adjazenz via Hamming<=maxHamming
        /** @var array<int,list<int>> $adj */
        $adj = \array_fill(0, $n, []);
        $hashes = [];
        for ($i = 0; $i < $n; $i++) {
            $hashes[$i] = (string) $items[$i]->getPhash();
        }

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($this->hammingHex($hashes[$i], $hashes[$j]) <= $this->maxHamming) {
                    $adj[$i][] = $j;
                    $adj[$j][] = $i;
                }
            }
        }

        $seen = \array_fill(0, $n, false);
        $out  = [];
        for ($i = 0; $i < $n; $i++) {
            if ($seen[$i]) {
                continue;
            }
            // BFS
            $queue = [$i];
            $seen[$i] = true;
            $comp = [];
            while ($queue !== []) {
                $v = \array_shift($queue);
                if ($v === null) {
                    break;
                }
                $comp[] = $items[$v];
                foreach ($adj[$v] as $w) {
                    if (!$seen[$w]) {
                        $seen[$w] = true;
                        $queue[] = $w;
                    }
                }
            }
            if ($comp !== []) {
                $out[] = $comp;
            }
        }

        return $out;
    }

    private function hammingHex(string $a, string $b): int
    {
        $len = \min(\strlen($a), \strlen($b));
        $dist = 0;
        for ($i = 0; $i < $len; $i++) {
            $x = \hexdec($a[$i]) ^ \hexdec($b[$i]);
            // count bits in nibble
            $dist += [0,1,1,2,1,2,2,3,1,2,2,3,2,3,3,4][$x] ?? 0;
        }
        // different length → penalize remaining nibbles as full mismatches
        $extra = \abs(\strlen($a) - \strlen($b)) * 4;
        return $dist + $extra;
    }
}
