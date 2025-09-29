<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use RuntimeException;
use MagicSunday\Memories\Entity\Media;

/**
 * Extracts metadata for the given file and enriches a Media entity.
 */
interface MetadataExtractorInterface
{
    /**
     * Extract metadata and populate the given Media entity.
     *
     * @param string $filepath Absolute path to the media file.
     * @param Media  $media    Media entity to populate.
     *
     * @return Media The same instance, enriched with metadata.
     *
     * @throws RuntimeException When extraction fails.
     */
    public function extract(string $filepath, Media $media): Media;
}
