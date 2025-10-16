<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Contract;

use MagicSunday\Memories\Entity\Cluster;

/**
 * Refreshes persisted clusters with curated member overlays.
 */
interface ClusterCuratedOverlayRefresherInterface
{
    /**
     * @return array{raw_count:int, curated_count:int, overlay_count:int}
     */
    public function refreshExistingCluster(Cluster $cluster): array;
}
