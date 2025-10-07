<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Quality;

use DateTimeInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Support\IndexLogEntry;
use MagicSunday\Memories\Support\IndexLogHelper;

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
        private float $minResolutionMegapixels = 2.0,
        private float $lowScoreThreshold = 0.35,
        private float $lowSharpnessThreshold = 0.20,
        private float $lowExposureThreshold = 0.25,
        private float $lowNoiseQualityThreshold = 0.25,
        private float $clippingLowQualityThreshold = 0.15,
        private float $clippingPenaltyWeight = 0.5,
        private int $noiseReferenceYear = 2015,
        private float $noiseThresholdDecayPerYear = 0.01,
        private float $noiseThresholdFloor = 0.10,
    ) {
    }

    public function aggregate(Media $media): void
    {
        $megapixels     = $this->resolutionMegapixels($media);
        $sharpnessScore = $this->clamp01($media->getSharpness());
        $noiseScore     = $this->isoScore($media->getIso());
        $exposureScore  = $this->exposureScore($media);
        $clippingShare  = $this->clamp01($media->getQualityClipping());

        $media->setQualityClipping($clippingShare);

        $qualityScore = $this->weightedScore([
            [$sharpnessScore, 0.50],
            [$exposureScore, 0.30],
            [$noiseScore, 0.20],
        ]);

        if ($qualityScore !== null && $clippingShare !== null && $clippingShare > 0.0) {
            $penaltyFactor = max(0.0, min(1.0, $this->clippingPenaltyWeight * $clippingShare));
            $qualityScore  = max(0.0, $qualityScore * (1.0 - $penaltyFactor));
        }

        $media->setQualityScore($qualityScore);
        $media->setQualityExposure($exposureScore);
        $media->setQualityNoise($noiseScore);

        $isLowQuality = false;
        $noiseThreshold = $this->noiseThresholdFor($media);
        if ($qualityScore !== null && $qualityScore < $this->lowScoreThreshold) {
            $isLowQuality = true;
        }

        if ($megapixels !== null && $megapixels < $this->minResolutionMegapixels) {
            $isLowQuality = true;
        }

        if ($sharpnessScore !== null && $sharpnessScore < $this->lowSharpnessThreshold) {
            $isLowQuality = true;
        }

        if ($exposureScore !== null && $exposureScore < $this->lowExposureThreshold) {
            $isLowQuality = true;
        }

        if ($noiseScore !== null && $noiseScore < $noiseThreshold) {
            $isLowQuality = true;
        }

        if ($clippingShare !== null && $clippingShare > $this->clippingLowQualityThreshold) {
            $isLowQuality = true;
        }

        $media->setLowQuality($isLowQuality);

        $this->appendQualitySummary(
            $media,
            $isLowQuality,
            $qualityScore,
            $sharpnessScore,
            $noiseScore,
            $exposureScore,
            $clippingShare,
            $noiseThreshold,
        );
    }

    private function weightedScore(array $components): ?float
    {
        $sum       = 0.0;
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

    private function exposureScore(Media $media): ?float
    {
        $brightness     = $this->clamp01($media->getBrightness());
        $contrast       = $this->clamp01($media->getContrast());
        $balancedBright = $brightness !== null ? $this->balancedScore($brightness, 0.55, 0.35) : null;

        return $this->weightedScore([
            [$balancedBright, 0.60],
            [$contrast, 0.40],
        ]);
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

    private function resolutionMegapixels(Media $media): ?float
    {
        $width  = $media->getWidth();
        $height = $media->getHeight();
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return null;
        }

        return ((float) $width * (float) $height) / 1_000_000.0;
    }

    private function noiseThresholdFor(Media $media): float
    {
        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeInterface) {
            return $this->lowNoiseQualityThreshold;
        }

        $deltaYears = $this->noiseReferenceYear - (int) $takenAt->format('Y');
        if ($deltaYears <= 0) {
            return $this->lowNoiseQualityThreshold;
        }

        $adjusted = $this->lowNoiseQualityThreshold - ($deltaYears * $this->noiseThresholdDecayPerYear);

        return max($this->noiseThresholdFloor, $adjusted);
    }

    private function appendQualitySummary(
        Media $media,
        bool $isLowQuality,
        ?float $qualityScore,
        ?float $sharpnessScore,
        ?float $noiseScore,
        ?float $exposureScore,
        ?float $clippingShare,
        float $noiseThreshold,
    ): void {
        $context = [
            'status' => $isLowQuality ? 'low' : 'ok',
            'noiseThreshold' => $noiseThreshold,
        ];

        if ($qualityScore !== null) {
            $context['score'] = $qualityScore;
        }

        if ($sharpnessScore !== null) {
            $context['sharpness'] = $sharpnessScore;
        }

        if ($noiseScore !== null) {
            $context['noise'] = $noiseScore;
        }

        if ($exposureScore !== null) {
            $context['exposure'] = $exposureScore;
        }

        if ($clippingShare !== null) {
            $context['clipping'] = $clippingShare;
        }

        $takenAt = $media->getTakenAt();
        if ($takenAt instanceof DateTimeInterface) {
            $context['takenAt'] = $takenAt;
        }

        IndexLogHelper::appendEntry(
            $media,
            IndexLogEntry::info(
                'metadata.quality',
                'aggregate',
                'Qualitätsmetriken aggregiert.',
                $context,
            ),
        );
    }
}
