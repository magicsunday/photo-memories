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
 * Value object describing per-media quality metrics normalised to the 0..1 range.
 */
final readonly class ImageQualityScore
{
    public function __construct(
        public float $sharpness,
        public float $exposure,
        public float $contrast,
        public float $noise,
        public float $blockiness,
        public float $keyframeQuality,
        public float $clipping,
        public float $videoBonus = 0.0,
        public float $videoPenalty = 0.0,
        public ?ImageQualityRawMetrics $rawMetrics = null,
    ) {
    }
}
