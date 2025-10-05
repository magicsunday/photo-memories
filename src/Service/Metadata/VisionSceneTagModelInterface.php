<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Media;

/**
 * Provides scene classification scores based on vision embeddings (e.g. CLIP).
 */
interface VisionSceneTagModelInterface
{
    /**
     * Predicts scene labels with confidence scores in the range [0, 1].
     *
     * @return array<string, float> Map of label => score
     */
    public function predict(string $filepath, Media $media): array;
}
