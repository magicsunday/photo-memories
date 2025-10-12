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
final class ImageQualityScore
{
    public function __construct(
        public readonly float $sharpness,
        public readonly float $exposure,
        public readonly float $contrast,
        public readonly float $noise,
        public readonly float $blockiness,
        public readonly float $keyframeQuality,
        public readonly float $clipping,
        public readonly float $videoBonus = 0.0,
        public readonly float $videoPenalty = 0.0,
        public readonly ?ImageQualityRawMetrics $rawMetrics = null,
    ) {
    }
}
