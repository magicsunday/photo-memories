<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Quality;

use MagicSunday\Memories\Entity\Media;

/**
 * Describes an estimator capable of deriving perceptual quality metrics for media items.
 */
interface ImageQualityEstimatorInterface
{
    public function scoreStill(Media $media): ImageQualityScore;

    public function scoreVideo(Media $media): ImageQualityScore;
}
