<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use InvalidArgumentException;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Quality\MediaQualityAggregator;
use MagicSunday\Memories\Service\Metadata\Support\GdImageToolsTrait;
use MagicSunday\Memories\Service\Metadata\Support\ImageAdapterInterface;

use function count;
use function is_file;
use function is_string;
use function log;
use function max;
use function min;
use function sqrt;
use function str_starts_with;

/**
 * Computes simple vision quality features from a downscaled grayscale matrix.
 * Backend-agnostic via ImageAdapter (Imagick preferred, fallback to GD).
 */
final readonly class VisionSignatureExtractor implements SingleMetadataExtractorInterface
{
    use GdImageToolsTrait;

    public function __construct(
        private readonly MediaQualityAggregator $qualityAggregator,
        private int $sampleSize = 96, // square downsample for analysis
    ) {
        if ($this->sampleSize < 16) {
            throw new InvalidArgumentException('sampleSize must be >= 16');
        }
    }

    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();

        return is_string($mime) && str_starts_with($mime, 'image/');
    }

    public function extract(string $filepath, Media $media): Media
    {
        $src = $this->resolveImageSource($media) ?? $filepath;
        if (!is_file($src)) {
            return $media;
        }

        $adapter = $this->createImageAdapter($src);
        if (!$adapter instanceof ImageAdapterInterface) {
            // gracefully skip if no backend available
            return $media;
        }

        // Build grayscale matrix at fixed sample size (square)
        $mat = $this->grayscaleMatrixFromAdapter($adapter, $this->sampleSize, $this->sampleSize);
        $adapter->destroy();

        // Brightness / contrast / entropy
        [$brightness, $contrast, $entropy] = $this->lumaStats($mat);

        // Sharpness (Laplacian variance on luma)
        $sharpness = $this->laplacianVariance($mat);

        // Optional: a simple proxy "colorfulness" via luma local contrast
        // (echte Farb-Varianz erfordert RGB; dieser Proxy ist robust & günstig)
        $colorfulness = $this->localContrastProxy($mat);

        $media->setBrightness($brightness);
        $media->setContrast($contrast);
        $media->setEntropy($entropy);
        $media->setSharpness($sharpness);
        $media->setColorfulness($colorfulness);

        $this->qualityAggregator->aggregate($media);

        return $media;
    }

    /**
     * @param array<int, array<int, float>> $m
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function lumaStats(array $m): array
    {
        $h = count($m);
        $w = $h > 0 ? count($m[0]) : 0;
        if ($w < 1 || $h < 1) {
            return [0.0, 0.0, 0.0];
        }

        $n    = $w * $h;
        $sum  = 0.0;
        $sum2 = 0.0;
        /** @var array<int,int> $hist */
        $hist = [];

        for ($y = 0; $y < $h; ++$y) {
            $row = $m[$y];
            for ($x = 0; $x < $w; ++$x) {
                $L = $row[$x]; // [0..255]
                $sum += $L;
                $sum2 += $L * $L;
                $k        = (int) $L;
                $hist[$k] = ($hist[$k] ?? 0) + 1;
            }
        }

        $mean = $sum / (float) $n;
        $var  = max(0.0, ($sum2 / (float) $n) - $mean * $mean);
        $std  = sqrt($var);

        $entropy = 0.0;
        foreach ($hist as $count) {
            $p = $count / (float) $n;
            $entropy += -$p * log($p + 1e-12) / log(2.0);
        }

        // Normalize into [0..1]
        return [
            max(0.0, min(1.0, $mean / 255.0)),     // brightness
            max(0.0, min(1.0, $std / 128.0)),     // contrast
            max(0.0, min(1.0, $entropy / 8.0)),    // entropy (8-bit)
        ];
    }

    /**
     * @param array<int, array<int, float>> $m
     */
    private function laplacianVariance(array $m): float
    {
        $h = count($m);
        $w = $h > 0 ? count($m[0]) : 0;
        if ($w < 3 || $h < 3) {
            return 0.0;
        }

        $sum  = 0.0;
        $sum2 = 0.0;
        $n    = 0;

        for ($y = 1; $y < $h - 1; ++$y) {
            for ($x = 1; $x < $w - 1; ++$x) {
                $L = 4.0 * $m[$y][$x]
                    - $m[$y][$x - 1]
                    - $m[$y][$x + 1]
                    - $m[$y - 1][$x]
                    - $m[$y + 1][$x];
                $sum += $L;
                $sum2 += $L * $L;
                ++$n;
            }
        }

        if ($n < 1) {
            return 0.0;
        }

        $mean = $sum / (float) $n;
        $var  = max(0.0, ($sum2 / (float) $n) - $mean * $mean);

        // Soft normalization – empirically robust in [0..1]
        return max(0.0, min(1.0, $var / 500.0));
    }

    /**
     * Simple colorfulness proxy using local luma contrast (no RGB needed).
     * Values ~0 (monotone) .. ~1 (highly varied).
     *
     * @param array<int, array<int, float>> $m
     */
    private function localContrastProxy(array $m): float
    {
        $h = count($m);
        $w = $h > 0 ? count($m[0]) : 0;
        if ($w < 4 || $h < 4) {
            return 0.0;
        }

        $acc = 0.0;
        $n   = 0;
        // 4-pixel stride for speed – good enough for a proxy
        for ($y = 0; $y < $h - 1; $y += 4) {
            for ($x = 0; $x < $w - 1; $x += 4) {
                $a    = $m[$y][$x];
                $b    = $m[$y][$x + 1];
                $c    = $m[$y + 1][$x];
                $d    = $m[$y + 1][$x + 1];
                $mean = ($a + $b + $c + $d) / 4.0;
                $var  = (($a - $mean) ** 2 + ($b - $mean) ** 2 + ($c - $mean) ** 2 + ($d - $mean) ** 2) / 4.0;
                $acc += sqrt($var);
                ++$n;
            }
        }

        if ($n < 1) {
            return 0.0;
        }

        // Normalize rough range into [0..1]
        $avg = $acc / (float) $n;        // ~[0..128]

        return max(0.0, min(1.0, $avg / 64.0));
    }
}
