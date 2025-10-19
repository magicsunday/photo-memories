<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Context;
use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ContextualClusterBridgeTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\GeoCell;
use MagicSunday\Memories\Utility\LocationHelper;

use function abs;
use function array_fill;
use function array_shift;
use function count;
use function hexdec;
use function is_string;
use function min;
use function strlen;
use function substr;
use function sprintf;

/**
 * Class PhashSimilarityStrategy.
 */
final readonly class PhashSimilarityStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    private const MAX_HAMMING_CEILING = 5;

    use ContextualClusterBridgeTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use MediaFilterTrait;
    use ProgressAwareClusterTrait;

    public function __construct(
        private LocationHelper $locationHelper,
        private int $maxHamming = self::MAX_HAMMING_CEILING,
        private int $minItemsPerBucket = 2,
    ) {
        if ($this->maxHamming < 0 || $this->maxHamming > self::MAX_HAMMING_CEILING) {
            throw new InvalidArgumentException(
                sprintf('maxHamming must be between 0 and %d.', self::MAX_HAMMING_CEILING)
            );
        }

        if ($this->minItemsPerBucket < 1) {
            throw new InvalidArgumentException('minItemsPerBucket must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'phash_similarity';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        // 1) nur mit pHash
        $with = $this->filterTimestampedItemsBy(
            $items,
            static fn (Media $m): bool => is_string($m->getPhash()) && $m->getPhash() !== ''
        );

        if ($with === []) {
            return [];
        }

        // 2) Buckets nach kurzer Präfix-Ähnlichkeit (4 Hex = 16 Bits); reduziert O(n^2)
        /** @var array<string, list<Media>> $buckets */
        $buckets = [];
        foreach ($with as $m) {
            $p         = (string) $m->getPhash();
            $bucketKey = substr($p, 0, 4);
            $buckets[$bucketKey] ??= [];
            $buckets[$bucketKey][] = $m;
        }

        /** @var array<string, list<Media>> $eligibleBuckets */
        $eligibleBuckets = $this->filterGroupsByMinItems($buckets, $this->minItemsPerBucket);

        // 3) Pro Bucket Komponenten nach Hamming-Distanz
        $drafts = [];
        foreach ($eligibleBuckets as $group) {
            foreach ($this->components($group) as $comp) {
                if (count($comp) < $this->minItemsPerBucket) {
                    continue;
                }

                $centroid = $this->computeCentroid($comp);

                $params = [
                    'time_range'    => $this->computeTimeRange($comp),
                    'members_count' => count($comp),
                ];

                $lat = $centroid['lat'] ?? null;
                $lon = $centroid['lon'] ?? null;
                if ($lat !== null && $lon !== null) {
                    $params['centroid_cell7'] = GeoCell::fromPoint($lat, $lon, 7);
                }

                $params = $this->appendLocationMetadata($comp, $params);
                $peopleParams = $this->buildPeopleParams($comp);
                $params       = [...$params, ...$peopleParams];

                $drafts[] = new ClusterDraft(
                    algorithm: $this->name(),
                    params: $params,
                    centroid: $centroid,
                    members: $this->toMemberIds($comp)
                );
            }
        }

        return $drafts;
    }

    /** @param list<Media> $items @return list<list<Media>> */
    private function components(array $items): array
    {
        $n = count($items);
        if ($n <= 1) {
            return $n === 1 ? [[$items[0]]] : [];
        }

        // Adjazenz via Hamming<=maxHamming
        /** @var array<int,list<int>> $adj */
        $adj    = array_fill(0, $n, []);
        $hashes = [];
        foreach ($items as $index => $item) {
            $hashes[$index] = (string) $item->getPhash();
        }

        for ($i = 0; $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                if ($this->hammingHex($hashes[$i], $hashes[$j]) <= $this->maxHamming) {
                    $adj[$i][] = $j;
                    $adj[$j][] = $i;
                }
            }
        }

        $seen = array_fill(0, $n, false);
        $out  = [];
        for ($i = 0; $i < $n; ++$i) {
            if ($seen[$i]) {
                continue;
            }

            // BFS
            $queue    = [$i];
            $seen[$i] = true;
            $comp     = [];
            while ($queue !== []) {
                $v = array_shift($queue);
                if ($v === null) {
                    break;
                }

                $comp[] = $items[$v];
                foreach ($adj[$v] as $w) {
                    if (!$seen[$w]) {
                        $seen[$w] = true;
                        $queue[]  = $w;
                    }
                }
            }

            $out[] = $comp;
        }

        return $out;
    }

    private function hammingHex(string $a, string $b): int
    {
        $len  = min(strlen($a), strlen($b));
        $dist = 0;
        for ($i = 0; $i < $len; ++$i) {
            $x = hexdec($a[$i]) ^ hexdec($b[$i]);
            // count bits in nibble
            $dist += [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4][$x] ?? 0;
        }

        // different length → penalize remaining nibbles as full mismatches
        $extra = abs(strlen($a) - strlen($b)) * 4;

        return $dist + $extra;
    }
    /**
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void $update
     *
     * @return list<ClusterDraft>
     */
    public function clusterWithProgress(array $items, Context $ctx, callable $update): array
    {
        return $this->runWithDefaultProgress(
            $items,
            $ctx,
            $update,
            fn (array $payload, Context $context): array => $this->draft($payload, $context)
        );
    }

}
