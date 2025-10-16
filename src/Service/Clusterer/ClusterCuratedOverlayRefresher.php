<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use MagicSunday\Memories\Entity\Cluster;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterCuratedOverlayRefresherInterface;

/**
 * Delegates curated overlay refreshes to the persistence service.
 */
final readonly class ClusterCuratedOverlayRefresher implements ClusterCuratedOverlayRefresherInterface
{
    public function __construct(private ClusterPersistenceService $persistence)
    {
    }

    public function refreshExistingCluster(Cluster $cluster): array
    {
        return $this->persistence->refreshExistingCluster($cluster);
    }
}
