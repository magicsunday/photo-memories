<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Contract;

use MagicSunday\Memories\Clusterer\ClusterStrategyInterface;
use MagicSunday\Memories\Clusterer\Context;
use MagicSunday\Memories\Entity\Media;

/**
 * Extends a cluster strategy with progress aware execution.
 */
interface ProgressAwareClusterStrategyInterface extends ClusterStrategyInterface
{
    /**
     * @param list<Media>                                 $scope
     * @param Context                                     $ctx
     * @param callable(int $done, int $max, string $stage):void $update
     *
     * @return list<\MagicSunday\Memories\Clusterer\ClusterDraft>
     */
    public function clusterWithProgress(array $scope, Context $ctx, callable $update): array;
}

