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

        $quality     = $this->floatOrNull($params['quality_avg'] ?? null);
        $aesthetics  = $this->floatOrNull($params['aesthetics_score'] ?? null);
        $resolution  = $this->floatOrNull($params['quality_resolution'] ?? null);
        $sharpness   = $this->floatOrNull($params['quality_sharpness'] ?? null);
        $exposure    = $this->floatOrNull($params['quality_exposure'] ?? null);
        $contrast    = $this->floatOrNull($params['quality_contrast'] ?? null);
        $noise       = $this->floatOrNull($params['quality_noise'] ?? null);
        $blockiness  = $this->floatOrNull($params['quality_blockiness'] ?? null);
        $keyframe    = $this->floatOrNull($params['quality_video_keyframe'] ?? null);
        $videoBonus  = $this->floatOrNull($params['quality_video_bonus'] ?? null);
        $videoPenalty = $this->floatOrNull($params['quality_video_penalty'] ?? null);
        $clipping    = $this->floatOrNull($params['quality_clipping'] ?? null);
        $iso         = $this->floatOrNull($params['quality_iso'] ?? null);

        if (
            $quality === null
            || $aesthetics === null
            || $resolution === null
            || $sharpness === null
            || $exposure === null
            || $contrast === null
            || $noise === null
            || $blockiness === null
            || $keyframe === null
            || $clipping === null
            || $videoBonus === null
            || $videoPenalty === null
            || $iso === null
        ) {
            $metrics = $this->qualityAggregator->buildParams($mediaList);
            $quality ??= $metrics['quality_avg'];
            $aesthetics ??= $this->floatOrNull($metrics['aesthetics_score']);
            $resolution ??= $this->floatOrNull($metrics['quality_resolution']);
            $sharpness ??= $this->floatOrNull($metrics['quality_sharpness']);
            $exposure ??= $this->floatOrNull($metrics['quality_exposure']);
            $contrast ??= $this->floatOrNull($metrics['quality_contrast']);
            $noise ??= $this->floatOrNull($metrics['quality_noise']);
            $blockiness ??= $this->floatOrNull($metrics['quality_blockiness']);
            $keyframe ??= $this->floatOrNull($metrics['quality_video_keyframe']);
            $videoBonus ??= $this->floatOrNull($metrics['quality_video_bonus']);
            $videoPenalty ??= $this->floatOrNull($metrics['quality_video_penalty']);
            $clipping ??= $this->floatOrNull($metrics['quality_clipping']);
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

        if ($exposure !== null) {
            $cluster->setParam('quality_exposure', $exposure);
        }

        if ($contrast !== null) {
            $cluster->setParam('quality_contrast', $contrast);
        }

        if ($noise !== null) {
            $cluster->setParam('quality_noise', $noise);
        }

        if ($blockiness !== null) {
            $cluster->setParam('quality_blockiness', $blockiness);
        }

        if ($keyframe !== null) {
            $cluster->setParam('quality_video_keyframe', $keyframe);
        }

        if ($videoBonus !== null) {
            $cluster->setParam('quality_video_bonus', $videoBonus);
        }

        if ($videoPenalty !== null) {
            $cluster->setParam('quality_video_penalty', $videoPenalty);
        }

        if ($clipping !== null) {
            $cluster->setParam('quality_clipping', $clipping);
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
