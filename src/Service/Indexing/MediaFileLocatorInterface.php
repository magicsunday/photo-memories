<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing;

/**
 * Locate media files that should be processed by the ingestion pipeline.
 */
interface MediaFileLocatorInterface
{
    /**
     * Locate supported files under the given base directory.
     *
     * @param bool           $includeVideos whether video files should be included
     *
     * @return iterable<array-key, string>
     */
    public function locate(string $baseDir, ?int $maxFiles = null, bool $includeVideos = true): iterable;
}
