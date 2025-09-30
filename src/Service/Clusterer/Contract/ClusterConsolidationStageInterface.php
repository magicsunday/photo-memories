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
 * Defines a single step of the cluster consolidation pipeline.
 */
interface ClusterConsolidationStageInterface
{
    /**
     * Human readable label for progress reporting.
     */
    public function getLabel(): string;

    /**
     * Processes the given drafts and returns the transformed result.
     *
     * @param list<ClusterDraft> $drafts
     * @param callable(int $done, int $max):void|null $progress
     *
     * @return list<ClusterDraft>
     */
    public function process(array $drafts, ?callable $progress = null): array;
}
