<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

/**
 * Immutable runtime configuration for clustering.
 */
final class ClusterConfig
{
    public function __construct(
        public readonly float $minSimilarity = 0.55,
        public readonly int $minClusterSize = 2,
        public readonly bool $normalizeWeights = true
    ) {
        if ($this->minSimilarity < 0.0 || $this->minSimilarity > 1.0) {
            throw new \InvalidArgumentException('minSimilarity must be in [0,1].');
        }
        if ($this->minClusterSize < 1) {
            throw new \InvalidArgumentException('minClusterSize must be >= 1.');
        }
    }
}
