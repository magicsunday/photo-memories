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
 * Role of a media item within a persisted cluster.
 */
enum ClusterMemberRole: string
{
    case PRIMARY   = 'primary';
    case SUPPORT   = 'support';
    case DUPLICATE = 'duplicate';
}
