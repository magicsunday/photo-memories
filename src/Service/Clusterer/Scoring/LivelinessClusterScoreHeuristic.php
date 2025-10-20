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

use function array_sum;
use function count;
use function max;
use function min;

/**
 * Scores how lively a cluster feels based on motion-heavy media.
 */
final class LivelinessClusterScoreHeuristic extends AbstractClusterScoreHeuristic
{
    private float $motionCoverageWeight;

    public function __construct(
        private readonly float $videoShareWeight,
        private readonly float $livePhotoShareWeight,
        private readonly float $motionWeight,
        private readonly float $videoShareTarget,
        private readonly float $livePhotoShareTarget,
        private readonly float $motionShareTarget,
        private readonly float $motionBlurThreshold,
        private readonly float $motionBlurTarget,
        float $motionCoverageWeight,
        private readonly float $motionVideoDurationThreshold,
        private readonly float $motionVideoFpsThreshold,
    ) {
        $this->motionCoverageWeight = max(0.0, min(1.0, $motionCoverageWeight));
    }

    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $params = $cluster->getParams();

        $score          = $this->floatOrNull($params['liveliness'] ?? null);
        $videoShare     = $this->floatOrNull($params['liveliness_video_share'] ?? null);
        $liveShare      = $this->floatOrNull($params['liveliness_live_share'] ?? null);
        $motionShare    = $this->floatOrNull($params['liveliness_motion_share'] ?? null);
        $motionScore    = $this->floatOrNull($params['liveliness_motion_score'] ?? null);
        $motionBlurAvg  = $this->floatOrNull($params['liveliness_motion_blur_avg'] ?? null);

        if (
            $score === null
            || $videoShare === null
            || $liveShare === null
            || $motionShare === null
            || $motionScore === null
        ) {
            $mediaItems = $this->collectMediaItems($cluster, $mediaMap);
            $memberCount = count($cluster->getMembers());

            $videoCount       = 0;
            $liveCount        = 0;
            $motionCueCount   = 0;
            $motionBlurValues = [];

            foreach ($mediaItems as $media) {
                $isVideo = $media->isVideo();
                if ($isVideo) {
                    ++$videoCount;
                }

                $isLivePhoto = $media->getLivePairMedia() !== null || $media->getLivePairChecksum() !== null;
                if ($isLivePhoto) {
                    ++$liveCount;
                }

                $motionBlur = $this->floatOrNull($media->getMotionBlurScore());
                if ($motionBlur !== null) {
                    $motionBlurValues[] = $this->clamp01($motionBlur);
                }

                $videoDuration = $this->floatOrNull($media->getVideoDurationS());
                $videoFps      = $this->floatOrNull($media->getVideoFps());
                $slowMo        = $media->isSlowMo() ?? false;
                $stabilised    = $media->getVideoHasStabilization() ?? false;

                $motionBlurStrong = $motionBlur !== null && $motionBlur >= $this->motionBlurThreshold;
                $videoDurationStrong = $videoDuration !== null && $videoDuration >= $this->motionVideoDurationThreshold;
                $videoFpsStrong = $videoFps !== null && $videoFps >= $this->motionVideoFpsThreshold;

                if (
                    $isVideo
                    || $slowMo
                    || $stabilised
                    || $isLivePhoto
                    || $motionBlurStrong
                    || $videoDurationStrong
                    || $videoFpsStrong
                ) {
                    ++$motionCueCount;
                }
            }

            $videoShare  = $memberCount > 0 ? $videoCount / $memberCount : 0.0;
            $liveShare   = $memberCount > 0 ? $liveCount / $memberCount : 0.0;
            $motionShare = $memberCount > 0 ? $motionCueCount / $memberCount : 0.0;
            $motionBlurAvg = $motionBlurValues !== []
                ? array_sum($motionBlurValues) / count($motionBlurValues)
                : null;

            $motionCoverageComponent = $this->normalizeToTarget($motionShare, $this->motionShareTarget);
            $motionBlurComponent     = $motionBlurAvg !== null
                ? $this->normalizeToTarget($motionBlurAvg, $this->motionBlurTarget)
                : null;

            $coverageWeight = $this->motionCoverageWeight;
            $blurWeight     = $motionBlurComponent === null ? 0.0 : (1.0 - $coverageWeight);

            $motionScore = $this->combineScores([
                [$motionCoverageComponent, $coverageWeight],
                [$motionBlurComponent, $blurWeight],
            ], 0.0);

            $videoComponent = $this->normalizeToTarget($videoShare, $this->videoShareTarget);
            $liveComponent  = $this->normalizeToTarget($liveShare, $this->livePhotoShareTarget);

            $score = $this->combineScores([
                [$videoComponent, $this->videoShareWeight],
                [$liveComponent, $this->livePhotoShareWeight],
                [$motionScore, $this->motionWeight],
            ], 0.0);
        }

        $cluster->setParam('liveliness', $score ?? 0.0);
        $cluster->setParam('liveliness_video_share', $videoShare ?? 0.0);
        $cluster->setParam('liveliness_live_share', $liveShare ?? 0.0);
        $cluster->setParam('liveliness_motion_share', $motionShare ?? 0.0);
        $cluster->setParam('liveliness_motion_score', $motionScore ?? 0.0);

        if ($motionBlurAvg !== null) {
            $cluster->setParam('liveliness_motion_blur_avg', $motionBlurAvg);
        }
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['liveliness'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'liveliness';
    }

    private function normalizeToTarget(float $value, float $target): float
    {
        if ($target <= 0.0) {
            return $this->clamp01($value);
        }

        return $this->clamp01($value / $target);
    }
}
