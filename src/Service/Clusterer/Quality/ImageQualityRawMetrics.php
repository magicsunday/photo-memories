<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Quality;

/**
 * Captures the raw, unnormalised observations used to derive image quality scores.
 */
final readonly class ImageQualityRawMetrics
{
    public function __construct(
        public float $laplacianVariance,
        public float $clippingShare,
        public float $contrastStandardDeviation,
        public float $noiseEstimate,
        public float $blockinessEstimate,
    ) {
    }
}
