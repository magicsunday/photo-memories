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
 * Consolidates cluster drafts into a final selection.
 */
interface ClusterConsolidatorInterface
{
    /**
     * @param list<ClusterDraft>                                     $drafts
     * @param callable(int $done, int $max, string $stage):void|null $progress
     *
     * @return list<ClusterDraft>
     */
    public function consolidate(array $drafts, ?callable $progress = null): array;
}
