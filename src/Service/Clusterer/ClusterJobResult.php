<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

final class ClusterJobResult
{
    public function __construct(
        private readonly int $totalMediaCount,
        private readonly int $loadedMediaCount,
        private readonly int $draftCount,
        private readonly int $consolidatedCount,
        private readonly int $persistedCount,
        private readonly int $deletedCount,
        private readonly bool $dryRun,
    ) {
    }

    public function getTotalMediaCount(): int
    {
        return $this->totalMediaCount;
    }

    public function getLoadedMediaCount(): int
    {
        return $this->loadedMediaCount;
    }

    public function getDraftCount(): int
    {
        return $this->draftCount;
    }

    public function getConsolidatedCount(): int
    {
        return $this->consolidatedCount;
    }

    public function getPersistedCount(): int
    {
        return $this->persistedCount;
    }

    public function getDeletedCount(): int
    {
        return $this->deletedCount;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
}
