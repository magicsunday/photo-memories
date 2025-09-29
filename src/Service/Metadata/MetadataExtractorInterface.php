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
use RuntimeException;

/**
 * Extracts metadata for the given file and enriches a Media entity.
 */
interface MetadataExtractorInterface
{
    /**
     * Extract metadata and populate the given Media entity.
     *
     * @param string $filepath absolute path to the media file
     * @param Media  $media    media entity to populate
     *
     * @return Media the same instance, enriched with metadata
     *
     * @throws RuntimeException when extraction fails
     */
    public function extract(string $filepath, Media $media): Media;
}
