<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Slideshow;

use MagicSunday\Memories\Entity\Media;

/**
 * Handles slideshow generation scheduling and status reporting.
 */
interface SlideshowVideoManagerInterface
{
    /**
     * @param list<int>        $memberIds
     * @param array<int,Media> $mediaMap
     */
    public function ensureForItem(
        string $itemId,
        array $memberIds,
        array $mediaMap,
        ?string $title = null,
        ?string $subtitle = null,
    ): SlideshowVideoStatus;

    public function getStatusForItem(string $itemId): SlideshowVideoStatus;

    public function resolveVideoPath(string $itemId): ?string;
}
