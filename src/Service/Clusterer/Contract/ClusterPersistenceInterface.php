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
 * Interface ClusterPersistenceInterface.
 */
interface ClusterPersistenceInterface
{
    /**
     * @param list<ClusterDraft>      $drafts
     * @param callable(int):void|null $onBatchPersisted
     */
    public function persistBatched(array $drafts, int $batchSize, ?callable $onBatchPersisted): int;

    /**
     * Persist clusters while flushing after every entity to keep memory usage low.
     *
     * @param iterable<ClusterDraft>  $drafts
     * @param callable(int):void|null $onPersisted
     */
    public function persistStreaming(iterable $drafts, ?callable $onPersisted): int;

    /**
     * @param list<string> $algorithms
     */
    public function deleteByAlgorithms(array $algorithms): int;

    public function deleteAll(): int;
}
