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

use function array_slice;
use function ceil;
use function count;
use function floor;
use function max;
use function sort;
use function usort;

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

    private readonly int $topK;

    public function __construct(
        float $qualityBaselineMegapixels = 12.0,
        ?ImageQualityEstimatorInterface $estimator = null,
        ?array $qualityWeights = null,
        float $videoBonusWeight = self::DEFAULT_VIDEO_BONUS_WEIGHT,
        float $videoPenaltyWeight = self::DEFAULT_VIDEO_PENALTY_WEIGHT,
        int $topK = 0,
    ) {
        $this->qualityBaselineMegapixels = $qualityBaselineMegapixels;
        $this->estimator                 = $estimator ?? new DeterministicImageQualityEstimator();
        $this->qualityWeights            = $qualityWeights ?? self::DEFAULT_QUALITY_WEIGHTS;
        $this->videoBonusWeight          = $videoBonusWeight;
        $this->videoPenaltyWeight        = $videoPenaltyWeight;
        $this->topK                      = max(0, $topK);
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

            $videoBonusValue   = null;
            $videoPenaltyValue = null;

            if ($media->isVideo()) {
                $videoBonusValue   = $this->clamp01($score->videoBonus);
                $videoPenaltyValue = $this->clamp01($score->videoPenalty);
            }

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
                'video_bonus'      => $videoBonusValue,
                'video_penalty'    => $videoPenaltyValue,
            ];
        }

        $resolutionPercentiles = $this->percentileRange($resolutionSamples);
        $sharpnessPercentiles  = $this->percentileRange($sharpnessSamples);
        $contrastPercentiles   = $this->percentileRange($contrastSamples);
        $noisePercentiles      = $this->percentileRange($noiseSamples);
        $blockinessPercentiles = $this->percentileRange($blockinessSamples);
        $clippingPercentiles   = $this->percentileRange($clippingSamples);
        $keyframePercentiles   = $this->percentileRange($keyframeSamples);

        $members = [];

        foreach ($measurements as $measurement) {
            $resolution = $this->scaleWithFallback(
                $measurement['resolution_raw'] ?? null,
                $resolutionPercentiles,
                true,
                $measurement['resolution_norm'] ?? null,
            );
            $sharpness = $this->scaleWithFallback(
                $measurement['sharpness_raw'] ?? null,
                $sharpnessPercentiles,
                true,
                $measurement['sharpness_norm'] ?? null,
            );
            $exposure = $this->scaleWithFallback(
                $measurement['clipping_raw'] ?? null,
                $clippingPercentiles,
                false,
                $measurement['exposure_norm'] ?? null,
            );
            $contrast = $this->scaleWithFallback(
                $measurement['contrast_raw'] ?? null,
                $contrastPercentiles,
                true,
                $measurement['contrast_norm'] ?? null,
            );
            $noise = $this->scaleWithFallback(
                $measurement['noise_raw'] ?? null,
                $noisePercentiles,
                false,
                $measurement['noise_norm'] ?? null,
            );
            $blockiness = $this->scaleWithFallback(
                $measurement['blockiness_raw'] ?? null,
                $blockinessPercentiles,
                false,
                $measurement['blockiness_norm'] ?? null,
            );
            $keyframe = $this->scaleWithFallback(
                $measurement['keyframe_raw'] ?? null,
                $keyframePercentiles,
                true,
                $measurement['keyframe_norm'] ?? null,
            );

            $components = $this->buildQualityComponents([
                'resolution' => $resolution,
                'sharpness'  => $sharpness,
                'exposure'   => $exposure,
                'contrast'   => $contrast,
                'noise'      => $noise,
                'blockiness' => $blockiness,
                'keyframe'   => $keyframe,
            ]);

            $quality = $this->combineScores($components, null);
            $aesthetics = $this->combineScores([
                [$exposure, 0.45],
                [$contrast, 0.35],
                [$sharpness, 0.20],
            ], null);

            $clipping = $measurement['clipping_raw'] ?? null;
            if ($clipping !== null) {
                $clipping = $this->clamp01($clipping);
            }

            $members[] = [
                'quality'       => $quality,
                'aesthetics'    => $aesthetics,
                'resolution'    => $resolution,
                'sharpness'     => $sharpness,
                'exposure'      => $exposure,
                'contrast'      => $contrast,
                'noise'         => $noise,
                'blockiness'    => $blockiness,
                'keyframe'      => $keyframe,
                'clipping'      => $clipping,
                'iso'           => $noise,
                'video_bonus'   => $measurement['video_bonus'] ?? null,
                'video_penalty' => $measurement['video_penalty'] ?? null,
            ];
        }

        $aggregated = $this->aggregateFromMembers($members);

        return [
            ...$aggregated,
            'quality_members' => $members,
        ];
    }

    /**
     * @param list<array<string,float|null>> $memberMetrics
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
     *     quality_iso: float|null,
     * }
     */
    public function aggregateFromMembers(array $memberMetrics): array
    {
        $topMembers = $this->selectTopQualityMembers($memberMetrics);
        $top        = $this->computeAggregateMetrics($topMembers);
        $overall    = $this->computeAggregateMetrics($memberMetrics);

        return [
            'quality_avg'        => $top['quality'] ?? 0.0,
            'aesthetics_score'   => $top['aesthetics'],
            'quality_resolution' => $overall['resolution'],
            'quality_sharpness'  => $overall['sharpness'],
            'quality_exposure'   => $overall['exposure'],
            'quality_contrast'   => $overall['contrast'],
            'quality_noise'      => $overall['noise'],
            'quality_blockiness' => $overall['blockiness'],
            'quality_video_keyframe' => $overall['keyframe'],
            'quality_video_bonus'    => $overall['video_bonus'],
            'quality_video_penalty'  => $overall['video_penalty'],
            'quality_clipping'       => $overall['clipping'],
            'quality_iso'            => $overall['iso'],
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
     * @param list<array<string,float|null>> $members
     */
    private function averageMemberMetric(array $members, string $key): ?float
    {
        $sum   = 0.0;
        $count = 0;

        foreach ($members as $member) {
            $value = $member[$key] ?? null;
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

    /**
     * @param list<array<string,float|null>> $members
     *
     * @return list<array<string,float|null>>
     */
    private function selectTopQualityMembers(array $members): array
    {
        if ($this->topK <= 0 || $this->topK >= count($members)) {
            return $members;
        }

        $sorted = $members;
        usort($sorted, static function (array $left, array $right): int {
            $leftQuality  = $left['quality'] ?? null;
            $rightQuality = $right['quality'] ?? null;

            if ($leftQuality === null && $rightQuality === null) {
                return 0;
            }

            if ($leftQuality === null) {
                return 1;
            }

            if ($rightQuality === null) {
                return -1;
            }

            return $rightQuality <=> $leftQuality;
        });

        return array_slice($sorted, 0, $this->topK);
    }

    /**
     * @param list<array<string,float|null>> $members
     *
     * @return array{
     *     quality: float|null,
     *     aesthetics: float|null,
     *     resolution: float|null,
     *     sharpness: float|null,
     *     exposure: float|null,
     *     contrast: float|null,
     *     noise: float|null,
     *     blockiness: float|null,
     *     keyframe: float|null,
     *     clipping: float|null,
     *     iso: float|null,
     *     video_bonus: float|null,
     *     video_penalty: float|null,
     * }
     */
    private function computeAggregateMetrics(array $members): array
    {
        if ($members === []) {
            return [
                'quality'       => null,
                'aesthetics'    => null,
                'resolution'    => null,
                'sharpness'     => null,
                'exposure'      => null,
                'contrast'      => null,
                'noise'         => null,
                'blockiness'    => null,
                'keyframe'      => null,
                'clipping'      => null,
                'iso'           => null,
                'video_bonus'   => null,
                'video_penalty' => null,
            ];
        }

        $resolution = $this->averageMemberMetric($members, 'resolution');
        $sharpness  = $this->averageMemberMetric($members, 'sharpness');
        $exposure   = $this->averageMemberMetric($members, 'exposure');
        $contrast   = $this->averageMemberMetric($members, 'contrast');
        $noise      = $this->averageMemberMetric($members, 'noise');
        $blockiness = $this->averageMemberMetric($members, 'blockiness');
        $keyframe   = $this->averageMemberMetric($members, 'keyframe');

        $videoBonus   = $this->averageMemberMetric($members, 'video_bonus');
        $videoPenalty = $this->averageMemberMetric($members, 'video_penalty');

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

        $clipping = $this->averageMemberMetric($members, 'clipping');
        if ($clipping !== null) {
            $clipping = $this->clamp01($clipping);
        }

        $iso = $noise;

        return [
            'quality'       => $quality,
            'aesthetics'    => $aesthetics,
            'resolution'    => $resolution,
            'sharpness'     => $sharpness,
            'exposure'      => $exposure,
            'contrast'      => $contrast,
            'noise'         => $noise,
            'blockiness'    => $blockiness,
            'keyframe'      => $keyframe,
            'clipping'      => $clipping,
            'iso'           => $iso,
            'video_bonus'   => $videoBonus,
            'video_penalty' => $videoPenalty,
        ];
    }
}
