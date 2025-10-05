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

use function log;
use function max;
use function min;

/**
 * Aggregates per-media quality metrics for cluster level annotations.
 */
final class ClusterQualityAggregator
{
    public function __construct(private float $qualityBaselineMegapixels = 12.0)
    {
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
     *     quality_iso: float|null
     * }
     */
    public function buildParams(array $mediaItems): array
    {
        $resolutionSum   = 0.0;
        $resolutionCount = 0;
        $sharpnessSum    = 0.0;
        $sharpnessCount  = 0;
        $isoSum          = 0.0;
        $isoCount        = 0;

        $brightnessSum   = 0.0;
        $brightnessCount = 0;
        $contrastSum     = 0.0;
        $contrastCount   = 0;
        $entropySum      = 0.0;
        $entropyCount    = 0;
        $colorSum        = 0.0;
        $colorCount      = 0;

        foreach ($mediaItems as $media) {
            $width  = $media->getWidth();
            $height = $media->getHeight();
            if ($width !== null && $height !== null && $width > 0 && $height > 0) {
                $megapixels = ((float) $width * (float) $height) / 1_000_000.0;
                $resolutionSum += $this->clamp01($megapixels / max(1e-6, $this->qualityBaselineMegapixels));
                ++$resolutionCount;
            }

            $sharpness = $media->getSharpness();
            if ($sharpness !== null) {
                $sharpnessSum += $this->clamp01($sharpness);
                ++$sharpnessCount;
            }

            $iso = $media->getIso();
            if ($iso !== null && $iso > 0) {
                $isoSum += $this->normalizeIso($iso);
                ++$isoCount;
            }

            $brightness = $media->getBrightness();
            if ($brightness !== null) {
                $brightnessSum += $this->clamp01($brightness);
                ++$brightnessCount;
            }

            $contrast = $media->getContrast();
            if ($contrast !== null) {
                $contrastSum += $this->clamp01($contrast);
                ++$contrastCount;
            }

            $entropy = $media->getEntropy();
            if ($entropy !== null) {
                $entropySum += $this->clamp01($entropy);
                ++$entropyCount;
            }

            $colorfulness = $media->getColorfulness();
            if ($colorfulness !== null) {
                $colorSum += $this->clamp01($colorfulness);
                ++$colorCount;
            }
        }

        $resolution = $resolutionCount > 0 ? $resolutionSum / $resolutionCount : null;
        $sharpness  = $sharpnessCount > 0 ? $sharpnessSum / $sharpnessCount : null;
        $iso        = $isoCount > 0 ? $isoSum / $isoCount : null;

        $quality = $this->combineScores([
            [$resolution, 0.45],
            [$sharpness, 0.35],
            [$iso, 0.20],
        ], 0.5);

        $brightnessAvg = $brightnessCount > 0 ? $brightnessSum / $brightnessCount : null;
        $contrastAvg   = $contrastCount > 0 ? $contrastSum / $contrastCount : null;
        $entropyAvg    = $entropyCount > 0 ? $entropySum / $entropyCount : null;
        $colorAvg      = $colorCount > 0 ? $colorSum / $colorCount : null;

        $aesthetics = $this->combineScores([
            [$brightnessAvg !== null ? $this->balancedScore($brightnessAvg, 0.55, 0.35) : null, 0.30],
            [$contrastAvg, 0.20],
            [$entropyAvg, 0.25],
            [$colorAvg, 0.25],
        ], null);

        return [
            'quality_avg'        => $quality ?? 0.0,
            'aesthetics_score'   => $aesthetics,
            'quality_resolution' => $resolution,
            'quality_sharpness'  => $sharpness,
            'quality_iso'        => $iso,
        ];
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

    private function balancedScore(float $value, float $target, float $tolerance): float
    {
        $delta = abs($value - $target);
        if ($delta >= $tolerance) {
            return 0.0;
        }

        return $this->clamp01(1.0 - ($delta / $tolerance));
    }

    private function normalizeIso(int $iso): float
    {
        $min   = 50.0;
        $max   = 6400.0;
        $value = (float) max($min, min($max, $iso));
        $ratio = log($value / $min) / log($max / $min);

        return $this->clamp01(1.0 - $ratio);
    }
}
