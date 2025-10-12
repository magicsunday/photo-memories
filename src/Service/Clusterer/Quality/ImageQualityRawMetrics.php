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
final class ImageQualityRawMetrics
{
    public function __construct(
        public readonly float $laplacianVariance,
        public readonly float $clippingShare,
        public readonly float $contrastStandardDeviation,
        public readonly float $noiseEstimate,
        public readonly float $blockinessEstimate,
    ) {
    }
}
