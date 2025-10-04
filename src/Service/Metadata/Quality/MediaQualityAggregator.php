<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Quality;

use MagicSunday\Memories\Entity\Media;

use function abs;
use function log;
use function max;
use function min;

/**
 * Aggregates per-media quality metrics into reusable summary scores.
 */
final class MediaQualityAggregator
{
    public function __construct(
        private float $qualityBaselineMegapixels = 12.0,
        private float $lowScoreThreshold = 0.35,
        private float $lowResolutionThreshold = 0.30,
        private float $lowSharpnessThreshold = 0.30,
        private float $lowExposureThreshold = 0.25,
        private float $lowNoiseThreshold = 0.25,
    ) {
    }

    public function aggregate(Media $media): void
    {
        $resolutionScore = $this->resolutionScore($media);
        $sharpnessScore  = $this->clamp01($media->getSharpness());
        $noiseScore      = $this->isoScore($media->getIso());

        $qualityScore = $this->weightedScore([
            [$resolutionScore, 0.45],
            [$sharpnessScore, 0.35],
            [$noiseScore, 0.20],
        ]);

        $brightness      = $this->clamp01($media->getBrightness());
        $contrast        = $this->clamp01($media->getContrast());
        $balancedBright  = $brightness !== null ? $this->balancedScore($brightness, 0.55, 0.35) : null;
        $exposureScore   = $this->weightedScore([
            [$balancedBright, 0.60],
            [$contrast, 0.40],
        ]);

        $media->setQualityScore($qualityScore);
        $media->setQualityExposure($exposureScore);
        $media->setQualityNoise($noiseScore);

        $isLowQuality = false;
        if ($qualityScore !== null && $qualityScore < $this->lowScoreThreshold) {
            $isLowQuality = true;
        }

        if ($resolutionScore !== null && $resolutionScore < $this->lowResolutionThreshold) {
            $isLowQuality = true;
        }

        if ($sharpnessScore !== null && $sharpnessScore < $this->lowSharpnessThreshold) {
            $isLowQuality = true;
        }

        if ($exposureScore !== null && $exposureScore < $this->lowExposureThreshold) {
            $isLowQuality = true;
        }

        if ($noiseScore !== null && $noiseScore < $this->lowNoiseThreshold) {
            $isLowQuality = true;
        }

        $media->setLowQuality($isLowQuality);
    }

    private function weightedScore(array $components): ?float
    {
        $sum = 0.0;
        $weightSum = 0.0;

        foreach ($components as [$value, $weight]) {
            if ($value === null) {
                continue;
            }

            $sum += $value * $weight;
            $weightSum += $weight;
        }

        if ($weightSum <= 0.0) {
            return null;
        }

        return $this->clamp01($sum / $weightSum);
    }

    private function clamp01(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    private function balancedScore(float $value, float $target, float $tolerance): float
    {
        $delta = abs($value - $target);
        if ($delta >= $tolerance) {
            return 0.0;
        }

        return 1.0 - ($delta / $tolerance);
    }

    private function isoScore(?int $iso): ?float
    {
        if ($iso === null || $iso <= 0) {
            return null;
        }

        $min   = 50.0;
        $max   = 6400.0;
        $value = (float) max($min, min($max, $iso));

        return 1.0 - (log($value / $min) / log($max / $min));
    }

    private function resolutionScore(Media $media): ?float
    {
        $width  = $media->getWidth();
        $height = $media->getHeight();
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return null;
        }

        $megapixels = ((float) $width * (float) $height) / 1_000_000.0;

        return $this->clamp01($megapixels / max(0.000001, $this->qualityBaselineMegapixels));
    }
}
