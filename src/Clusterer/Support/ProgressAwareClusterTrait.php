<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use function max;

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
}
