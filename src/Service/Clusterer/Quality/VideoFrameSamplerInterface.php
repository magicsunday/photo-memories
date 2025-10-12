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
 * Provides access to representative poster frames for video quality analysis.
 */
interface VideoFrameSamplerInterface
{
    /**
     * Extracts a luminance matrix from a representative video keyframe.
     *
     * @param callable(string): (array{0: array<int,array<int,float>>, 1: int, 2: int}|null) $loader
     *
     * @return array{0: array<int,array<int,float>>, 1: int, 2: int}|null
     */
    public function sampleLumaMatrix(Media $media, callable $loader): ?array;
}
