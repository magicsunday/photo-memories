<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Quality\DeterministicImageQualityEstimator;
use MagicSunday\Memories\Service\Clusterer\Quality\ImageQualityEstimatorInterface;

use function max;
use function min;

/**
 * Aggregates per-media quality metrics for cluster level annotations.
 */
final class ClusterQualityAggregator
{
    public const DEFAULT_QUALITY_WEIGHTS = [
        'resolution' => 0.18,
        'sharpness'  => 0.24,
        'exposure'   => 0.18,
        'contrast'   => 0.16,
        'noise'      => 0.12,
        'blockiness' => 0.07,
        'keyframe'   => 0.05,
    ];

    public const DEFAULT_VIDEO_BONUS_WEIGHT   = 0.08;
    public const DEFAULT_VIDEO_PENALTY_WEIGHT = 0.12;

    private readonly float $qualityBaselineMegapixels;

    private readonly ImageQualityEstimatorInterface $estimator;

    /**
     * @var array<string,float>
     */
    private readonly array $qualityWeights;

    private readonly float $videoBonusWeight;

    private readonly float $videoPenaltyWeight;

    public function __construct(
        float $qualityBaselineMegapixels = 12.0,
        ?ImageQualityEstimatorInterface $estimator = null,
        ?array $qualityWeights = null,
        float $videoBonusWeight = self::DEFAULT_VIDEO_BONUS_WEIGHT,
        float $videoPenaltyWeight = self::DEFAULT_VIDEO_PENALTY_WEIGHT,
    ) {
        $this->qualityBaselineMegapixels = $qualityBaselineMegapixels;
        $this->estimator                 = $estimator ?? new DeterministicImageQualityEstimator();
        $this->qualityWeights            = $qualityWeights ?? self::DEFAULT_QUALITY_WEIGHTS;
        $this->videoBonusWeight          = $videoBonusWeight;
        $this->videoPenaltyWeight        = $videoPenaltyWeight;
    }

    /**
     * Builds the quality related parameters for a list of media items.
     *
     * @param list<Media> $mediaItems
     *
     * @return array{
     *     quality_avg: float,
     *     aesthetics_score: float|null,
     *     quality_resolution: float|null,
     *     quality_sharpness: float|null,
     *     quality_exposure: float|null,
     *     quality_contrast: float|null,
     *     quality_noise: float|null,
     *     quality_blockiness: float|null,
     *     quality_video_keyframe: float|null,
     *     quality_video_bonus: float|null,
     *     quality_video_penalty: float|null,
     *     quality_clipping: float|null,
     *     quality_iso: float|null
     * }
     */
    public function buildParams(array $mediaItems): array
    {
        $resolutionSum   = 0.0;
        $resolutionCount = 0;

        $sharpnessSum   = 0.0;
        $sharpnessCount = 0;

        $exposureSum   = 0.0;
        $exposureCount = 0;

        $contrastSum   = 0.0;
        $contrastCount = 0;

        $noiseSum   = 0.0;
        $noiseCount = 0;

        $blockinessSum   = 0.0;
        $blockinessCount = 0;

        $keyframeSum   = 0.0;
        $keyframeCount = 0;

        $clippingSum   = 0.0;
        $clippingCount = 0;

        $videoBonusSum   = 0.0;
        $videoBonusCount = 0;

        $videoPenaltySum   = 0.0;
        $videoPenaltyCount = 0;

        foreach ($mediaItems as $media) {
            $width  = $media->getWidth();
            $height = $media->getHeight();
            if ($width !== null && $height !== null && $width > 0 && $height > 0) {
                $megapixels = ((float) $width * (float) $height) / 1_000_000.0;
                $resolutionSum += $this->clamp01($megapixels / max(1e-6, $this->qualityBaselineMegapixels));
                ++$resolutionCount;
            }

            $score = $media->isVideo()
                ? $this->estimator->scoreVideo($media)
                : $this->estimator->scoreStill($media);

            $sharpnessSum += $this->clamp01($score->sharpness);
            ++$sharpnessCount;

            $exposureSum += $this->clamp01($score->exposure);
            ++$exposureCount;

            $contrastSum += $this->clamp01($score->contrast);
            ++$contrastCount;

            $noiseSum += $this->clamp01($score->noise);
            ++$noiseCount;

            $blockinessSum += $this->clamp01($score->blockiness);
            ++$blockinessCount;

            $keyframeSum += $this->clamp01($score->keyframeQuality);
            ++$keyframeCount;

            $clippingSum += $this->clamp01($score->clipping);
            ++$clippingCount;

            if ($media->isVideo()) {
                $videoBonusSum += $this->clamp01($score->videoBonus);
                ++$videoBonusCount;

                $videoPenaltySum += $this->clamp01($score->videoPenalty);
                ++$videoPenaltyCount;
            }
        }

        $resolution = $resolutionCount > 0 ? $resolutionSum / $resolutionCount : null;
        $sharpness  = $sharpnessCount > 0 ? $sharpnessSum / $sharpnessCount : null;
        $exposure   = $exposureCount > 0 ? $exposureSum / $exposureCount : null;
        $contrast   = $contrastCount > 0 ? $contrastSum / $contrastCount : null;
        $noise      = $noiseCount > 0 ? $noiseSum / $noiseCount : null;
        $blockiness = $blockinessCount > 0 ? $blockinessSum / $blockinessCount : null;
        $keyframe   = $keyframeCount > 0 ? $keyframeSum / $keyframeCount : null;
        $clipping   = $clippingCount > 0 ? $clippingSum / $clippingCount : null;

        $quality = $this->combineScores($this->buildQualityComponents([
            'resolution' => $resolution,
            'sharpness'  => $sharpness,
            'exposure'   => $exposure,
            'contrast'   => $contrast,
            'noise'      => $noise,
            'blockiness' => $blockiness,
            'keyframe'   => $keyframe,
        ]), null);

        if ($quality !== null) {
            if ($videoBonusCount > 0 && $this->videoBonusWeight > 0.0) {
                $quality += $this->videoBonusWeight * ($videoBonusSum / $videoBonusCount);
            }

            if ($videoPenaltyCount > 0 && $this->videoPenaltyWeight > 0.0) {
                $quality -= $this->videoPenaltyWeight * ($videoPenaltySum / $videoPenaltyCount);
            }

            $quality = $this->clamp01($quality);
        }

        $aesthetics = $this->combineScores([
            [$exposure, 0.45],
            [$contrast, 0.35],
            [$sharpness, 0.20],
        ], null);

        return [
            'quality_avg'        => $quality ?? 0.0,
            'aesthetics_score'   => $aesthetics,
            'quality_resolution' => $resolution,
            'quality_sharpness'  => $sharpness,
            'quality_exposure'   => $exposure,
            'quality_contrast'   => $contrast,
            'quality_noise'      => $noise,
            'quality_blockiness' => $blockiness,
            'quality_video_keyframe' => $keyframe,
            'quality_video_bonus'    => $videoBonusCount > 0 ? $videoBonusSum / $videoBonusCount : null,
            'quality_video_penalty'  => $videoPenaltyCount > 0 ? $videoPenaltySum / $videoPenaltyCount : null,
            'quality_clipping'       => $clipping,
            'quality_iso'            => $noise,
        ];
    }

    /**
     * @param array<string,float|null> $values
     *
     * @return array<array{0: float|null, 1: float}>
     */
    private function buildQualityComponents(array $values): array
    {
        $components = [];
        foreach ($this->qualityWeights as $key => $weight) {
            if ($weight <= 0.0) {
                continue;
            }

            $value = $values[$key] ?? null;
            $components[] = [$value, $weight];
        }

        return $components;
    }

    private function clamp01(?float $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    /**
     * @param array<array{0: float|null, 1: float}> $components
     */
    private function combineScores(array $components, ?float $default): ?float
    {
        $sum       = 0.0;
        $weightSum = 0.0;

        foreach ($components as [$value, $weight]) {
            if ($value === null) {
                continue;
            }

            $sum += $this->clamp01($value) * $weight;
            $weightSum += $weight;
        }

        if ($weightSum <= 0.0) {
            return $default;
        }

        return $sum / $weightSum;
    }

}
