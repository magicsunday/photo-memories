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
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;

/**
 * Class QualityClusterScoreHeuristic.
 */
final class QualityClusterScoreHeuristic extends AbstractClusterScoreHeuristic
{
    public function __construct(private readonly ClusterQualityAggregator $qualityAggregator)
    {
    }

    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $params    = $cluster->getParams();
        $mediaList = $this->collectMediaItems($cluster, $mediaMap);

        $quality    = $this->floatOrNull($params['quality_avg'] ?? null);
        $aesthetics = $this->floatOrNull($params['aesthetics_score'] ?? null);
        $resolution = $this->floatOrNull($params['quality_resolution'] ?? null);
        $sharpness  = $this->floatOrNull($params['quality_sharpness'] ?? null);
        $iso        = $this->floatOrNull($params['quality_iso'] ?? null);

        if ($quality === null || $aesthetics === null || $resolution === null || $sharpness === null || $iso === null) {
            $metrics = $this->qualityAggregator->buildParams($mediaList);
            $quality ??= $metrics['quality_avg'];
            $aesthetics ??= $this->floatOrNull($metrics['aesthetics_score']);
            $resolution ??= $this->floatOrNull($metrics['quality_resolution']);
            $sharpness ??= $this->floatOrNull($metrics['quality_sharpness']);
            $iso ??= $this->floatOrNull($metrics['quality_iso']);
        }

        $quality ??= 0.0;

        $cluster->setParam('quality_avg', $quality);
        if ($aesthetics !== null) {
            $cluster->setParam('aesthetics_score', $aesthetics);
        }

        if ($resolution !== null) {
            $cluster->setParam('quality_resolution', $resolution);
        }

        if ($sharpness !== null) {
            $cluster->setParam('quality_sharpness', $sharpness);
        }

        if ($iso !== null) {
            $cluster->setParam('quality_iso', $iso);
        }
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['quality_avg'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'quality';
    }
}
