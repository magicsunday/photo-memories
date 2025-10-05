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
     * @param list<Media>                   $items
     * @param callable(string,int,int):void $onStart
     * @param callable(string,int,int):void $onDone
     *
     * @return list<ClusterDraft>
     */
    public function build(array $items, callable $onStart, callable $onDone): array;
}
