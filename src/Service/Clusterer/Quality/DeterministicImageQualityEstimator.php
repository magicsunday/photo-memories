<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Quality;

use MagicSunday\Memories\Entity\Media;

use function abs;
use function file_get_contents;
use function function_exists;
use function imagecolorat;
use function imagecolorsforindex;
use function imagecreatefromstring;
use function imagedestroy;
use function imageistruecolor;
use function imagescale;
use function imagesx;
use function imagesy;
use function is_file;
use function is_readable;
use function is_string;
use function max;
use function min;
use function sqrt;

/**
 * Deterministic estimator that approximates common perceptual quality metrics using GD operations.
 */
final class DeterministicImageQualityEstimator implements ImageQualityEstimatorInterface
{
    private const int MAX_SAMPLE_DIMENSION = 512;

    private readonly VideoFrameSamplerInterface $videoSampler;

    public function __construct(?VideoFrameSamplerInterface $videoSampler = null)
    {
        $this->videoSampler = $videoSampler ?? new VideoFrameSampler();
    }

    public function scoreStill(Media $media): ImageQualityScore
    {
        $matrixData = $this->loadLumaMatrixFromPath($media->getPath());
        if ($matrixData === null) {
            return $this->neutralScore();
        }

        return $this->buildBaseScore($matrixData);
    }

    public function scoreVideo(Media $media): ImageQualityScore
    {
        $matrixData = $this->sampleVideoMatrix($media);
        $base       = $matrixData !== null
            ? $this->buildBaseScore($matrixData)
            : $this->neutralScore();

        $bonus  = 0.0;
        $penalty = 0.0;

        $duration = $media->getVideoDurationS();
        if ($duration !== null) {
            if ($duration < 2.0) {
                $penalty += $this->normalizeRange(2.0 - $duration, 0.0, 2.0) * 0.5;
            } elseif ($duration <= 45.0) {
                $bonus += $this->normalizeRange($duration, 2.0, 45.0) * 0.4;
            } elseif ($duration <= 120.0) {
                $bonus += 0.4 - ($this->normalizeRange($duration, 45.0, 120.0) * 0.2);
            } else {
                $penalty += $this->normalizeRange($duration, 120.0, 480.0) * 0.4;
            }
        }

        $fps = $media->getVideoFps();
        if ($fps !== null) {
            if ($fps < 20.0) {
                $penalty += $this->normalizeRange(20.0 - $fps, 0.0, 20.0) * 0.3;
            } elseif ($fps >= 40.0) {
                $bonus += $this->normalizeRange($fps, 40.0, 120.0) * 0.1;
            }
        }

        $bonus   = $this->clamp01($bonus);
        $penalty = $this->clamp01($penalty);

        $keyframe = $this->clamp01(
            ($base->keyframeQuality * 0.7)
            + (($base->sharpness + $base->contrast) * 0.15)
            - ($penalty * 0.25)
            + ($bonus * 0.2)
        );

        $blockiness = $this->clamp01(($base->blockiness * 0.85) + ((1.0 - $penalty) * 0.15));

        return new ImageQualityScore(
            sharpness: $base->sharpness,
            exposure: $base->exposure,
            contrast: $base->contrast,
            noise: $base->noise,
            blockiness: $blockiness,
            keyframeQuality: $keyframe,
            clipping: $base->clipping,
            videoBonus: $bonus,
            videoPenalty: $penalty,
            rawMetrics: $base->rawMetrics,
        );
    }

    /**
     * @param array{0: array<int,array<int,float>>, 1: int, 2: int} $matrixData
     */
    private function buildBaseScore(array $matrixData): ImageQualityScore
    {
        [$luma, $width, $height] = $matrixData;
        if ($width < 3 || $height < 3) {
            return $this->neutralScore();
        }

        $laplacianVar = $this->laplacianVariance($luma, $width, $height);
        $clipping     = $this->clippingShare($luma, $width, $height);
        $contrast     = $this->contrastStdDev($luma, $width, $height);
        $noiseLevel   = $this->noiseEstimate($luma, $width, $height);
        $blockiness   = $this->blockinessEstimate($luma, $width, $height);

        $sharpness = $this->normalizeRange($laplacianVar, 0.0004, 0.004);
        $exposure  = 1.0 - $this->normalizeRange($clipping, 0.02, 0.18);
        $contrastN = $this->normalizeRange($contrast, 0.05, 0.25);
        $noise     = 1.0 - $this->normalizeRange($noiseLevel, 0.005, 0.05);
        $blockN    = 1.0 - $this->normalizeRange($blockiness, 0.01, 0.08);
        $keyframe  = $this->clamp01(($sharpness * 0.6) + ($contrastN * 0.2) + ($noise * 0.2));

        return new ImageQualityScore(
            sharpness: $this->clamp01($sharpness),
            exposure: $this->clamp01($exposure),
            contrast: $this->clamp01($contrastN),
            noise: $this->clamp01($noise),
            blockiness: $this->clamp01($blockN),
            keyframeQuality: $keyframe,
            clipping: $this->clamp01($clipping),
            rawMetrics: new ImageQualityRawMetrics(
                laplacianVariance: $laplacianVar,
                clippingShare: $clipping,
                contrastStandardDeviation: $contrast,
                noiseEstimate: $noiseLevel,
                blockinessEstimate: $blockiness,
            ),
        );
    }

    /**
     * @return array{0: array<int,array<int,float>>, 1: int, 2: int}|null
     */
    private function loadLumaMatrixFromPath(?string $path): ?array
    {
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        if (!function_exists('imagecreatefromstring') || !function_exists('imagecolorat')) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $resource = @imagecreatefromstring($contents);
        if ($resource === false) {
            return null;
        }

        $width  = imagesx($resource);
        $height = imagesy($resource);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($resource);

            return null;
        }

        $largest = max($width, $height);
        if ($largest > self::MAX_SAMPLE_DIMENSION && function_exists('imagescale')) {
            $scale      = self::MAX_SAMPLE_DIMENSION / $largest;
            $scaled     = imagescale(
                $resource,
                max(1, (int) ($width * $scale)),
                max(1, (int) ($height * $scale))
            );
            if ($scaled !== false) {
                imagedestroy($resource);
                $resource = $scaled;
                $width    = imagesx($resource);
                $height   = imagesy($resource);
            }
        }

        $matrix    = [];
        $trueColor = imageistruecolor($resource);
        for ($y = 0; $y < $height; ++$y) {
            $row = [];
            for ($x = 0; $x < $width; ++$x) {
                if ($trueColor) {
                    $value = imagecolorat($resource, $x, $y);
                    $r     = ($value >> 16) & 0xFF;
                    $g     = ($value >> 8) & 0xFF;
                    $b     = $value & 0xFF;
                } else {
                    $index = imagecolorat($resource, $x, $y);
                    $color = imagecolorsforindex($resource, $index);
                    $r     = (int) ($color['red'] ?? 0);
                    $g     = (int) ($color['green'] ?? 0);
                    $b     = (int) ($color['blue'] ?? 0);
                }

                $row[] = (($r * 0.2126) + ($g * 0.7152) + ($b * 0.0722)) / 255.0;
            }

            $matrix[] = $row;
        }

        imagedestroy($resource);

        return [$matrix, $width, $height];
    }

    /**
     * @return array{0: array<int,array<int,float>>, 1: int, 2: int}|null
     */
    private function sampleVideoMatrix(Media $media): ?array
    {
        return $this->videoSampler->sampleLumaMatrix(
            $media,
            fn (string $posterPath): ?array => $this->loadLumaMatrixFromPath($posterPath)
        );
    }

    /**
     * @param array<int,array<int,float>> $luma
     */
    private function laplacianVariance(array $luma, int $width, int $height): float
    {
        $sum   = 0.0;
        $sumSq = 0.0;
        $count = 0;

        for ($y = 1; $y < $height - 1; ++$y) {
            for ($x = 1; $x < $width - 1; ++$x) {
                $center = $luma[$y][$x];
                $lap    = (4.0 * $center)
                    - $luma[$y - 1][$x]
                    - $luma[$y + 1][$x]
                    - $luma[$y][$x - 1]
                    - $luma[$y][$x + 1];

                $sum   += $lap;
                $sumSq += $lap * $lap;
                ++$count;
            }
        }

        if ($count === 0) {
            return 0.0;
        }

        $mean = $sum / $count;

        return max(0.0, ($sumSq / $count) - ($mean * $mean));
    }

    /**
     * @param array<int,array<int,float>> $luma
     */
    private function clippingShare(array $luma, int $width, int $height): float
    {
        $clipCount = 0;
        $total     = $width * $height;

        foreach ($luma as $row) {
            foreach ($row as $value) {
                if ($value <= 0.02 || $value >= 0.98) {
                    ++$clipCount;
                }
            }
        }

        if ($total <= 0) {
            return 0.0;
        }

        return min(1.0, $clipCount / $total);
    }

    /**
     * @param array<int,array<int,float>> $luma
     */
    private function contrastStdDev(array $luma, int $width, int $height): float
    {
        $sum   = 0.0;
        $sumSq = 0.0;
        $count = $width * $height;

        for ($y = 0; $y < $height; ++$y) {
            foreach ($luma[$y] as $value) {
                $sum   += $value;
                $sumSq += $value * $value;
            }
        }

        if ($count <= 0) {
            return 0.0;
        }

        $mean = $sum / $count;

        return sqrt(max(0.0, ($sumSq / $count) - ($mean * $mean)));
    }

    /**
     * @param array<int,array<int,float>> $luma
     */
    private function noiseEstimate(array $luma, int $width, int $height): float
    {
        $sum   = 0.0;
        $count = 0;

        for ($y = 1; $y < $height - 1; ++$y) {
            for ($x = 1; $x < $width - 1; ++$x) {
                $neighbourSum = 0.0;
                for ($dy = -1; $dy <= 1; ++$dy) {
                    for ($dx = -1; $dx <= 1; ++$dx) {
                        if ($dx === 0 && $dy === 0) {
                            continue;
                        }

                        $neighbourSum += $luma[$y + $dy][$x + $dx];
                    }
                }

                $average = $neighbourSum / 8.0;
                $sum    += abs($luma[$y][$x] - $average);
                ++$count;
            }
        }

        if ($count === 0) {
            return 0.0;
        }

        return $sum / $count;
    }

    /**
     * @param array<int,array<int,float>> $luma
     */
    private function blockinessEstimate(array $luma, int $width, int $height): float
    {
        $sum   = 0.0;
        $count = 0;

        for ($x = 8; $x < $width; $x += 8) {
            for ($y = 0; $y < $height; ++$y) {
                $sum += abs($luma[$y][$x - 1] - $luma[$y][$x]);
                ++$count;
            }
        }

        for ($y = 8; $y < $height; $y += 8) {
            for ($x = 0; $x < $width; ++$x) {
                $sum += abs($luma[$y - 1][$x] - $luma[$y][$x]);
                ++$count;
            }
        }

        if ($count === 0) {
            return 0.0;
        }

        return $sum / $count;
    }

    private function neutralScore(): ImageQualityScore
    {
        return new ImageQualityScore(
            sharpness: 0.5,
            exposure: 0.5,
            contrast: 0.5,
            noise: 0.5,
            blockiness: 0.5,
            keyframeQuality: 0.5,
            clipping: 0.0,
            rawMetrics: null,
        );
    }

    private function normalizeRange(float $value, float $low, float $high): float
    {
        if ($high <= $low) {
            return 0.0;
        }

        if ($value <= $low) {
            return 0.0;
        }

        if ($value >= $high) {
            return 1.0;
        }

        return ($value - $low) / ($high - $low);
    }

    private function clamp01(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}
