<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use RuntimeException;
use MagicSunday\Memories\Entity\Media;

/**
 * A single-purpose metadata extractor that may or may not apply
 * to the given file/media and enrich the Media entity accordingly.
 */
interface SingleMetadataExtractorInterface
{
    /**
     * Whether this extractor supports the given file/media context.
     *
     * Implement cheap checks here (e.g., mime starts with "image/" or "video/").
     */
    public function supports(string $filepath, Media $media): bool;

    /**
     * Extract and enrich metadata on the Media entity.
     *
     * Implementations must be idempotent and only set fields they own.
     *
     * @throws RuntimeException on extraction error
     */
    public function extract(string $filepath, Media $media): Media;
}
