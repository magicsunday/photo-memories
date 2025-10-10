<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Contract;

use MagicSunday\Memories\Clusterer\ClusterDraft;

/**
 * Defines the interface for services that curate cluster draft members.
 */
interface ClusterMemberSelectionServiceInterface
{
    public function curate(ClusterDraft $draft): ClusterDraft;
}
