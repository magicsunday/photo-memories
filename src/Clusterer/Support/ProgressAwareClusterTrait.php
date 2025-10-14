<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;

use function count;
use function max;
use function sprintf;

/**
 * Provides and exports a consistent progress notification helper for cluster strategies.
 */
trait ProgressAwareClusterTrait
{
    /**
     * Notifies a progress listener while guarding against division-by-zero scenarios.
     *
     * @param callable|null $update The optional listener receiving progress updates.
     * @param int           $done   The number of completed items.
     * @param int           $max    The total number of items to process.
     * @param string        $stage  A label describing the current processing stage.
     */
    protected function notifyProgress(?callable $update, int $done, int $max, string $stage): void
    {
        if ($update === null) {
            return;
        }

        $update($done, max(1, $max), $stage);
    }

    /**
     * Wraps a clustering run with coarse progress notifications.
     *
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void $update
     * @param callable(array<Media>):array<ClusterDraft>  $cluster
     *
     * @return list<ClusterDraft>
     */
    protected function runWithDefaultProgress(array $items, callable $update, callable $cluster): array
    {
        $max = max(1, count($items));

        $this->notifyProgress($update, 0, $max, sprintf('Filtern (%d)', count($items)));

        $drafts = $cluster($items);

        $this->notifyProgress($update, max(1, $max - 1), $max, 'Scoring & Metadaten');
        $this->notifyProgress($update, $max, $max, sprintf('Abgeschlossen (%d Memories)', count($drafts)));

        return $drafts;
    }
}
