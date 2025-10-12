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
use MagicSunday\Memories\Entity\Media;

/**
 * Interface HybridClustererInterface.
 */
interface HybridClustererInterface
{
    public function countStrategies(): int;

    /**
     * @param list<Media>                                             $items
     * @param callable(string $strategy, int $index, int $total):void|null $onStart
     * @param callable(string $strategy, int $index, int $total):void|null $onDone
     * @param callable(string $strategy, int $index, int $total): ?callable(int $done, int $max, string $stage)|null $makeProgress
     *
     * @return list<ClusterDraft>
     */
    public function build(
        array $items,
        ?callable $onStart,
        ?callable $onDone,
        ?callable $makeProgress = null,
    ): array;
}
