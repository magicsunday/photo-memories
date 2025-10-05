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
 * Broad content category markers assigned to media assets.
 */
enum ContentKind: string
{
    case PHOTO            = 'photo';
    case SCREENSHOT       = 'screenshot';
    case DOCUMENT         = 'document';
    case MAP              = 'map';
    case SCREEN_RECORDING = 'screenrecord';
    case OTHER            = 'other';
}
