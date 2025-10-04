<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Entity\Enum;

/**
 * Enumerates the possible sources for capture timestamps.
 */
enum TimeSource: string
{
    case EXIF = 'EXIF';
    case VIDEO_QUICKTIME = 'VIDEO_QUICKTIME';
    case FILE_MTIME = 'FILE_MTIME';
}
