<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Exif\Contract;

use MagicSunday\Memories\Entity\Media;

/**
 * Defines the contract for processing EXIF metadata fragments.
 */
interface ExifMetadataProcessorInterface
{
    /**
     * Applies EXIF data extracted from a media file onto the Media entity.
     *
     * @param array<string,mixed> $exif the complete EXIF array as returned by the extractor
     */
    public function process(array $exif, Media $media): void;
}
