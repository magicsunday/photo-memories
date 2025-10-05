<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use MagicSunday\Memories\Clusterer\ClusterDraft;

use function count;
use function max;
use function min;

/**
 * Class DensityClusterScoreHeuristic.
 */
final class DensityClusterScoreHeuristic extends AbstractTimeRangeClusterScoreHeuristic
{
    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $params    = $cluster->getParams();
        $timeRange = $this->ensureTimeRange($cluster, $mediaMap);
        $density   = $this->floatOrNull($params['density'] ?? null);

        if ($density === null) {
            $density = 0.0;
            if ($timeRange !== null) {
                $duration = max(1, (int) $timeRange['to'] - (int) $timeRange['from']);
                $n        = max(1, count($cluster->getMembers()));
                $density  = min(1.0, $n / max(60.0, (float) $duration / 60.0));
            }
        }

        if ($timeRange !== null || isset($params['density'])) {
            $cluster->setParam('density', $density);
        }
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['density'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'density';
    }
}
