<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;

/**
 * Interface ClustererInterface.
 */
interface ClustererInterface
{
    /**
     * Cluster the given media items into groups.
     *
     * @param list<Media> $items List of media items
     *
     * @return list<ClusterDraft> Clustered groups of items
     */
    public function cluster(array $items): array;
}
