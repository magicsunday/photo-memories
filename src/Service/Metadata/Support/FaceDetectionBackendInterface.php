<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use MagicSunday\Memories\Entity\Media;

/**
 * Abstraction for face-detection backends (CLI, PHP extensions, …).
 */
interface FaceDetectionBackendInterface
{
    public function detectFaces(string $imagePath, Media $media): FaceDetectionResult;
}
