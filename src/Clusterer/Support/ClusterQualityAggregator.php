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

use function ceil;
use function count;
use function floor;
use function max;
use function sort;

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
        /**
         * @var list<array<string,float|null>> $measurements
         */
        $measurements = [];

        $resolutionSamples = [];
        $sharpnessSamples  = [];
        $contrastSamples   = [];
        $noiseSamples      = [];
        $blockinessSamples = [];
        $clippingSamples   = [];
        $keyframeSamples   = [];

        $videoBonusSum   = 0.0;
        $videoBonusCount = 0;

        $videoPenaltySum   = 0.0;
        $videoPenaltyCount = 0;

        foreach ($mediaItems as $media) {
            $width  = $media->getWidth();
            $height = $media->getHeight();

            $resolutionRaw  = null;
            $resolutionNorm = null;

            if ($width !== null && $height !== null && $width > 0 && $height > 0) {
                $resolutionRaw  = ((float) $width * (float) $height) / 1_000_000.0;
                $resolutionNorm = $this->clamp01($resolutionRaw / max(1e-6, $this->qualityBaselineMegapixels));
                $resolutionSamples[] = $resolutionRaw;
            }

            $score      = $media->isVideo()
                ? $this->estimator->scoreVideo($media)
                : $this->estimator->scoreStill($media);
            $rawMetrics = $score->rawMetrics;

            $sharpnessRaw = $rawMetrics?->laplacianVariance;
            if ($sharpnessRaw !== null) {
                $sharpnessSamples[] = $sharpnessRaw;
            }

            $contrastRaw = $rawMetrics?->contrastStandardDeviation;
            if ($contrastRaw !== null) {
                $contrastSamples[] = $contrastRaw;
            }

            $noiseRaw = $rawMetrics?->noiseEstimate;
            if ($noiseRaw !== null) {
                $noiseSamples[] = $noiseRaw;
            }

            $blockinessRaw = $rawMetrics?->blockinessEstimate;
            if ($blockinessRaw !== null) {
                $blockinessSamples[] = $blockinessRaw;
            }

            $clippingRaw = $rawMetrics?->clippingShare ?? (float) $score->clipping;
            $clippingSamples[] = $clippingRaw;

            $keyframeRaw = (float) $score->keyframeQuality;
            $keyframeSamples[] = $keyframeRaw;

            $measurements[] = [
                'resolution_raw'   => $resolutionRaw,
                'resolution_norm'  => $resolutionNorm,
                'sharpness_raw'    => $sharpnessRaw,
                'sharpness_norm'   => $this->clamp01($score->sharpness),
                'contrast_raw'     => $contrastRaw,
                'contrast_norm'    => $this->clamp01($score->contrast),
                'noise_raw'        => $noiseRaw,
                'noise_norm'       => $this->clamp01($score->noise),
                'blockiness_raw'   => $blockinessRaw,
                'blockiness_norm'  => $this->clamp01($score->blockiness),
                'clipping_raw'     => $clippingRaw,
                'exposure_norm'    => $this->clamp01($score->exposure),
                'keyframe_raw'     => $keyframeRaw,
                'keyframe_norm'    => $this->clamp01($score->keyframeQuality),
            ];

            if ($media->isVideo()) {
                $videoBonusSum += $this->clamp01($score->videoBonus);
                ++$videoBonusCount;

                $videoPenaltySum += $this->clamp01($score->videoPenalty);
                ++$videoPenaltyCount;
            }
        }

        $resolutionPercentiles = $this->percentileRange($resolutionSamples);
        $sharpnessPercentiles  = $this->percentileRange($sharpnessSamples);
        $contrastPercentiles   = $this->percentileRange($contrastSamples);
        $noisePercentiles      = $this->percentileRange($noiseSamples);
        $blockinessPercentiles = $this->percentileRange($blockinessSamples);
        $clippingPercentiles   = $this->percentileRange($clippingSamples);
        $keyframePercentiles   = $this->percentileRange($keyframeSamples);

        $resolution = $this->averageScaled($measurements, 'resolution_raw', 'resolution_norm', $resolutionPercentiles, true);
        $sharpness  = $this->averageScaled($measurements, 'sharpness_raw', 'sharpness_norm', $sharpnessPercentiles, true);
        $exposure   = $this->averageScaled($measurements, 'clipping_raw', 'exposure_norm', $clippingPercentiles, false);
        $contrast   = $this->averageScaled($measurements, 'contrast_raw', 'contrast_norm', $contrastPercentiles, true);
        $noise      = $this->averageScaled($measurements, 'noise_raw', 'noise_norm', $noisePercentiles, false);
        $blockiness = $this->averageScaled($measurements, 'blockiness_raw', 'blockiness_norm', $blockinessPercentiles, false);
        $keyframe   = $this->averageScaled($measurements, 'keyframe_raw', 'keyframe_norm', $keyframePercentiles, true);

        $clipping = $this->averageRaw($measurements, 'clipping_raw');
        if ($clipping !== null) {
            $clipping = $this->clamp01($clipping);
        }

        $videoBonus  = $videoBonusCount > 0 ? $videoBonusSum / $videoBonusCount : null;
        $videoPenalty = $videoPenaltyCount > 0 ? $videoPenaltySum / $videoPenaltyCount : null;

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
            if ($videoBonus !== null && $this->videoBonusWeight > 0.0) {
                $quality += $this->videoBonusWeight * $videoBonus;
            }

            if ($videoPenalty !== null && $this->videoPenaltyWeight > 0.0) {
                $quality -= $this->videoPenaltyWeight * $videoPenalty;
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
            'quality_video_bonus'    => $videoBonus,
            'quality_video_penalty'  => $videoPenalty,
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

    /**
     * @param list<array<string,float|null>> $measurements
     * @param array{p10: float, p90: float}|null $percentiles
     */
    private function averageScaled(
        array $measurements,
        string $rawKey,
        string $fallbackKey,
        ?array $percentiles,
        bool $higherIsBetter,
    ): ?float {
        $sum   = 0.0;
        $count = 0;

        foreach ($measurements as $measurement) {
            $rawValue = $measurement[$rawKey] ?? null;
            $fallback = $measurement[$fallbackKey] ?? null;

            $scaled = $this->scaleWithFallback($rawValue, $percentiles, $higherIsBetter, $fallback);
            if ($scaled === null) {
                continue;
            }

            $sum += $scaled;
            ++$count;
        }

        if ($count === 0) {
            return null;
        }

        return $sum / $count;
    }

    /**
     * @param array{p10: float, p90: float}|null $percentiles
     */
    private function scaleWithFallback(
        ?float $value,
        ?array $percentiles,
        bool $higherIsBetter,
        ?float $fallback,
    ): ?float {
        if ($value === null || $percentiles === null || $percentiles['p90'] <= $percentiles['p10']) {
            return $fallback === null ? null : $this->clamp01($fallback);
        }

        $range = $percentiles['p90'] - $percentiles['p10'];
        if ($range <= 0.0) {
            return $fallback === null ? null : $this->clamp01($fallback);
        }

        $scaled = $higherIsBetter
            ? ($value - $percentiles['p10']) / $range
            : ($percentiles['p90'] - $value) / $range;

        return $this->clamp01($scaled);
    }

    /**
     * @param list<float> $samples
     *
     * @return array{p10: float, p90: float}|null
     */
    private function percentileRange(array $samples): ?array
    {
        if (count($samples) === 0) {
            return null;
        }

        sort($samples);

        $p10 = $this->percentile($samples, 0.10);
        $p90 = $this->percentile($samples, 0.90);

        if ($p10 === null || $p90 === null) {
            return null;
        }

        return ['p10' => $p10, 'p90' => $p90];
    }

    /**
     * @param list<float> $sorted
     */
    private function percentile(array $sorted, float $fraction): ?float
    {
        $count = count($sorted);
        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return $sorted[0];
        }

        $index = ($count - 1) * $fraction;
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $weight = $index - $lower;

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        return ($sorted[$lower] * (1.0 - $weight)) + ($sorted[$upper] * $weight);
    }

    /**
     * @param list<array<string,float|null>> $measurements
     */
    private function averageRaw(array $measurements, string $key): ?float
    {
        $sum   = 0.0;
        $count = 0;

        foreach ($measurements as $measurement) {
            $value = $measurement[$key] ?? null;
            if ($value === null) {
                continue;
            }

            $sum += $value;
            ++$count;
        }

        if ($count === 0) {
            return null;
        }

        return $sum / $count;
    }
}
