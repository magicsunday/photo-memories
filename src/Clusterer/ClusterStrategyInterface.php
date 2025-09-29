<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Entity\Media;

/**
 * Light-weight contract every strategy already follows in deinem Projekt.
 */
interface ClusterStrategyInterface
{
    /**
     * @param list<Media> $items Sorted or unsorted
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array;

    public function name(): string;
}
