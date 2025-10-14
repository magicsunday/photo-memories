<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use Closure;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;

use function count;
use function is_array;
use function is_object;
use function is_string;
use function max;
use function min;
use function sprintf;
use function spl_object_id;

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

        static $progressStates = [];

        $key      = $this->normaliseProgressKey($update);
        $stateKey = spl_object_id($this) . ':' . $key;

        $state = $progressStates[$stateKey] ?? ['done' => 0, 'max' => 0];

        $max = max(0, $max);
        if ($max > $state['max']) {
            $state['max'] = $max;
        }

        $done = max(0, $done);
        if ($state['max'] > 0) {
            $done = min($done, $state['max']);
        }

        if ($done < $state['done']) {
            $done = $state['done'];
        }

        $state['done'] = $done;
        $progressStates[$stateKey] = $state;

        $update($state['done'], max(1, $state['max']), $stage);

        if ($state['max'] > 0 && $state['done'] >= $state['max']) {
            unset($progressStates[$stateKey]);
        }
    }

    /**
     * Creates a stable identifier for callable listeners so state can be tracked per strategy invocation.
     */
    private function normaliseProgressKey(callable $update): string
    {
        if ($update instanceof Closure) {
            return 'closure:' . spl_object_id($update);
        }

        if (is_array($update)) {
            $target = $update[0];
            $method = (string) $update[1];

            if (is_object($target)) {
                return 'array:' . spl_object_id($target) . '->' . $method;
            }

            return 'array:' . (string) $target . '::' . $method;
        }

        if (is_string($update)) {
            return 'function:' . $update;
        }

        if (is_object($update)) {
            return 'invokable:' . spl_object_id($update);
        }

        return 'callable:' . spl_object_id((object) $update);
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
