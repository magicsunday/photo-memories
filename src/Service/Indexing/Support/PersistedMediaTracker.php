<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing\Support;

/**
 * Collects identifiers of media entities that have been persisted during an ingestion run.
 */
final class PersistedMediaTracker
{
    /**
     * @var list<int>
     */
    private array $persistedIds = [];

    public function record(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        if (\in_array($id, $this->persistedIds, true)) {
            return;
        }

        $this->persistedIds[] = $id;
    }

    /**
     * @return list<int>
     */
    public function drain(): array
    {
        $ids = $this->persistedIds;
        $this->persistedIds = [];

        return $ids;
    }

    public function clear(): void
    {
        $this->persistedIds = [];
    }
}
