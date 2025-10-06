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

use function is_string;
use function str_starts_with;

/**
 * Provides a shared implementation to detect image or video media by MIME type.
 */
trait ImageOrVideoSupportTrait
{
    private function supportsImageOrVideoMime(Media $media): bool
    {
        $mime = $media->getMime();

        if (!is_string($mime)) {
            return false;
        }

        return str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/');
    }
}
