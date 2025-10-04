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
use MagicSunday\Memories\Support\IndexLogHelper;

use function abs;
use function log;
use function implode;
use function max;
use function min;
use function sprintf;

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
        private float $clippingLowQualityThreshold = 0.15,
        private float $clippingPenaltyWeight = 0.5,
    ) {
    }

    public function aggregate(Media $media): void
    {
        $resolutionScore = $this->resolutionScore($media);
        $sharpnessScore  = $this->clamp01($media->getSharpness());
        $noiseScore      = $this->isoScore($media->getIso());
        $clippingShare   = $this->clamp01($media->getQualityClipping());

        $media->setQualityClipping($clippingShare);

        $qualityScore = $this->weightedScore([
            [$resolutionScore, 0.45],
            [$sharpnessScore, 0.35],
            [$noiseScore, 0.20],
        ]);

        if ($qualityScore !== null && $clippingShare !== null && $clippingShare > 0.0) {
            $penaltyFactor = max(0.0, min(1.0, $this->clippingPenaltyWeight * $clippingShare));
            $qualityScore  = max(0.0, $qualityScore * (1.0 - $penaltyFactor));
        }

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

        if ($clippingShare !== null && $clippingShare > $this->clippingLowQualityThreshold) {
            $isLowQuality = true;
        }

        $media->setLowQuality($isLowQuality);

        $this->appendQualitySummary($media, $isLowQuality, $sharpnessScore, $clippingShare);
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

    private function appendQualitySummary(Media $media, bool $isLowQuality, ?float $sharpnessScore, ?float $clippingShare): void
    {
        $parts = [sprintf('qlt=%s', $isLowQuality ? 'low' : 'ok')];

        if ($sharpnessScore !== null) {
            $parts[] = sprintf('sharp=%.2f', $sharpnessScore);
        }

        if ($clippingShare !== null) {
            $parts[] = sprintf('clip=%.2f', $clippingShare);
        }

        $line = implode('; ', $parts);
        IndexLogHelper::append($media, $line);
    }
}
