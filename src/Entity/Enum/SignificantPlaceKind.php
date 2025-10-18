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
 * Classification of significant places detected for the user.
 */
enum SignificantPlaceKind: string
{
    case HOME      = 'home';
    case WORK      = 'work';
    case FAVOURITE = 'favourite';
    case TRAVEL    = 'travel';
    case OTHER     = 'other';
}
