<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Thumbnail;

use MagicSunday\Memories\Entity\Media;
use RuntimeException;

/**
 * Creates thumbnails for a given media file.
 */
interface ThumbnailServiceInterface
{
    /**
     * Generate all configured thumbnails for the file and return a map "size => path".
     *
     * @param string $filepath absolute file path
     * @param Media  $media    Media entity for contextual info (e.g., mime, id).
     *
     * @return array<int,string> map of width (px) to absolute thumbnail path
     *
     * @throws RuntimeException when thumbnail generation fails
     */
    public function generateAll(string $filepath, Media $media): array;
}
