<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Contract;

use MagicSunday\Memories\Service\Clusterer\ClusterJobOptions;
use MagicSunday\Memories\Service\Clusterer\ClusterJobResult;

/**
 * Interface ClusterJobRunnerInterface.
 */
interface ClusterJobRunnerInterface
{
    public function run(ClusterJobOptions $options, ProgressReporterInterface $progressReporter): ClusterJobResult;
}
