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
use MagicSunday\Memories\Entity\Media;

use function max;

final class QualityClusterScoreHeuristic extends AbstractClusterScoreHeuristic
{
    public function __construct(private float $qualityBaselineMegapixels)
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
            $metrics = $this->computeQualityMetrics($mediaList);
            $quality ??= $metrics['quality'];
            $aesthetics ??= $metrics['aesthetics'];
            $resolution ??= $metrics['resolution'];
            $sharpness ??= $metrics['sharpness'];
            $iso ??= $metrics['iso'];
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

    /**
     * @param list<Media> $mediaItems
     *
     * @return array{quality:float,aesthetics:float|null,resolution:float|null,sharpness:float|null,iso:float|null}
     */
    private function computeQualityMetrics(array $mediaItems): array
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
            $w = $media->getWidth();
            $h = $media->getHeight();
            if ($w !== null && $h !== null && $w > 0 && $h > 0) {
                $megapixels = ((float) $w * (float) $h) / 1_000_000.0;
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
            'quality'    => $quality,
            'aesthetics' => $aesthetics,
            'resolution' => $resolution,
            'sharpness'  => $sharpness,
            'iso'        => $iso,
        ];
    }
}
