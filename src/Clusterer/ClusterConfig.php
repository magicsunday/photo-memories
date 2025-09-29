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

/**
 * Immutable runtime configuration for clustering.
 */
final readonly class ClusterConfig
{
    public function __construct(
        public float $minSimilarity = 0.55,
        public int $minClusterSize = 2,
        public bool $normalizeWeights = true,
    ) {
        if ($this->minSimilarity < 0.0 || $this->minSimilarity > 1.0) {
            throw new InvalidArgumentException('minSimilarity must be in [0,1].');
        }

        if ($this->minClusterSize < 1) {
            throw new InvalidArgumentException('minClusterSize must be >= 1.');
        }
    }
}
