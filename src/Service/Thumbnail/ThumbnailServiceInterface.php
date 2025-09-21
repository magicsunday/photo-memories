<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Thumbnail;

use MagicSunday\Memories\Entity\Media;

/**
 * Creates thumbnails for a given media file.
 */
interface ThumbnailServiceInterface
{
    /**
     * Generate all configured thumbnails for the file and return a map "size => path".
     *
     * @param string $filepath Absolute file path.
     * @param Media  $media    Media entity for contextual info (e.g., mime, id).
     *
     * @return array<int,string> Map of width (px) to absolute thumbnail path.
     *
     * @throws \RuntimeException When thumbnail generation fails.
     */
    public function generateAll(string $filepath, Media $media): array;
}
