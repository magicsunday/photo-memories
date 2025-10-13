<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use ImagickException;
use InvalidArgumentException;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Quality\MediaQualityAggregator;
use MagicSunday\Memories\Service\Metadata\Support\GdImageAdapter;
use MagicSunday\Memories\Service\Metadata\Support\GdImageToolsTrait;
use MagicSunday\Memories\Service\Metadata\Support\ImageAdapterInterface;
use MagicSunday\Memories\Service\Metadata\Support\ImagickImageAdapter;
use MagicSunday\Memories\Service\Metadata\Support\VideoPosterFrameTrait;

use function array_fill;
use function array_map;
use function count;
use function imagecolorat;
use function in_array;
use function is_file;
use function is_string;
use function log;
use function max;
use function min;
use function round;
use function sqrt;
use function str_starts_with;
use function trim;

/**
 * Computes simple vision quality features from a downscaled grayscale matrix.
 * Backend-agnostic via ImageAdapter (Imagick preferred, fallback to GD).
 */
final readonly class VisionSignatureExtractor implements SingleMetadataExtractorInterface
{
    use GdImageToolsTrait;
    use VideoPosterFrameTrait;

    private string $ffmpegBinary;

    private string $ffprobeBinary;

    public function __construct(
        private MediaQualityAggregator $qualityAggregator,
        private int $sampleSize = 96, // square downsample for analysis
        string $ffmpegBinary = 'ffmpeg',
        string $ffprobeBinary = 'ffprobe',
        private float $posterFrameSecond = 1.0,
    ) {
        if ($this->sampleSize < 16) {
            throw new InvalidArgumentException('sampleSize must be >= 16');
        }

        if ($this->posterFrameSecond < 0.0) {
            throw new InvalidArgumentException('posterFrameSecond must be >= 0');
        }

        $normalizedFfmpeg  = trim($ffmpegBinary);
        $normalizedFfprobe = trim($ffprobeBinary);

        if ($normalizedFfmpeg === '') {
            $normalizedFfmpeg = 'ffmpeg';
        }

        if ($normalizedFfprobe === '') {
            $normalizedFfprobe = 'ffprobe';
        }

        $this->ffmpegBinary  = $normalizedFfmpeg;
        $this->ffprobeBinary = $normalizedFfprobe;
    }

    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();

        if (is_string($mime)) {
            if (str_starts_with($mime, 'image/')) {
                return true;
            }

            if (str_starts_with($mime, 'video/')) {
                return true;
            }
        }

        return $media->isVideo();
    }

    public function extract(string $filepath, Media $media): Media
    {
        $sourcePath = $filepath;
        $posterPath = null;

        if ($this->isVideoMedia($media)) {
            $posterPath = $this->createPosterFrame($filepath);
            if ($posterPath === null) {
                return $media;
            }

            $sourcePath = $posterPath;
        } else {
            $resolved = $this->resolveImageSource($media);
            if (is_string($resolved)) {
                $sourcePath = $resolved;
            }
        }

        if (!is_file($sourcePath)) {
            $this->cleanupPosterFrame($posterPath);

            return $media;
        }

        try {
            $adapter = $this->createImageAdapter($sourcePath);
            if (!$adapter instanceof ImageAdapterInterface) {
                return $media;
            }

            if ($media->getWidth() === null) {
                $media->setWidth($adapter->getWidth());
            }

            if ($media->getHeight() === null) {
                $media->setHeight($adapter->getHeight());
            }

            $width  = $media->getWidth();
            $height = $media->getHeight();

            if ($width !== null && $height !== null && $width > 0 && $height > 0) {
                if ($media->isPortrait() === null || $media->isPanorama() === null) {
                    [$isPortrait, $isPanorama] = $this->deriveAspectFlags($media, $width, $height);

                    if ($media->isPortrait() === null) {
                        $media->setIsPortrait($isPortrait);
                    }

                    if ($media->isPanorama() === null) {
                        $media->setIsPanorama($isPanorama);
                    }
                }
            }

            $rgbMatrix     = $this->rgbMatrixFromAdapter($adapter, $this->sampleSize, $this->sampleSize);
            $lumaMatrix    = $this->lumaMatrixFromRgb($rgbMatrix);
            $clippingShare = $this->saturationClipping($rgbMatrix);
            $adapter->destroy();

            // Brightness / contrast / entropy
            [$brightness, $contrast, $entropy] = $this->lumaStats($lumaMatrix);

            // Sharpness (Laplacian variance on luma)
            $sharpness = $this->laplacianVariance($lumaMatrix);

            // Optional: a simple proxy "colorfulness" via luma local contrast
            $colorfulness = $this->localContrastProxy($lumaMatrix);

            $media->setBrightness($brightness);
            $media->setContrast($contrast);
            $media->setEntropy($entropy);
            $media->setSharpness($sharpness);
            $media->setColorfulness($colorfulness);
            $media->setQualityClipping($clippingShare);

            $this->qualityAggregator->aggregate($media);

            return $media;
        } finally {
            $this->cleanupPosterFrame($posterPath);
        }
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function deriveAspectFlags(Media $media, int $width, int $height): array
    {
        if ($width <= 0 || $height <= 0) {
            return [false, false];
        }

        $orientation = $media->getOrientation();
        if ($orientation !== null && in_array($orientation, [5, 6, 7, 8], true)) {
            [$width, $height] = [$height, $width];
        }

        $isPortrait = $height > $width && ((float) $height / (float) $width) >= 1.2;
        $isPanorama = $width > $height && ((float) $width / (float) $height) >= 2.4;

        return [$isPortrait, $isPanorama];
    }

    /**
     * @param ImageAdapterInterface $adapter
     * @param int                   $w
     * @param int                   $h
     *
     * @return array<int, array<int, array{0: float, 1: float, 2: float}>>
     *
     * @throws ImagickException
     */
    private function rgbMatrixFromAdapter(ImageAdapterInterface $adapter, int $w, int $h): array
    {
        if ($adapter instanceof ImagickImageAdapter) {
            $rgb = $adapter->exportRgbBytes($w, $h);
            $out = [];
            $idx = 0;

            for ($y = 0; $y < $h; ++$y) {
                $row = [];
                for ($x = 0; $x < $w; ++$x) {
                    $row[] = [
                        (float) $rgb[$idx++],
                        (float) $rgb[$idx++],
                        (float) $rgb[$idx++],
                    ];
                }

                $out[] = $row;
            }

            return $out;
        }

        $resized = $adapter->resize($w, $h);
        $out     = [];

        for ($y = 0; $y < $h; ++$y) {
            $row = [];
            for ($x = 0; $x < $w; ++$x) {
                if ($resized instanceof GdImageAdapter) {
                    $color = imagecolorat($resized->getNative(), $x, $y);
                    $r     = (float) (($color >> 16) & 0xFF);
                    $g     = (float) (($color >> 8) & 0xFF);
                    $b     = (float) ($color & 0xFF);
                } else {
                    $l = $resized->getLuma($x, $y);
                    $r = $l;
                    $g = $l;
                    $b = $l;
                }

                $row[] = [$r, $g, $b];
            }

            $out[] = $row;
        }

        $resized->destroy();

        return $out;
    }

    /**
     * @param array<int, array<int, array{0: float, 1: float, 2: float}>> $rgbMatrix
     *
     * @return array<int, array<int, float>>
     */
    private function lumaMatrixFromRgb(array $rgbMatrix): array
    {
        return array_map(
            static fn (array $row): array => array_map(
                static function (array $pixel): float {
                    [$r, $g, $b] = $pixel;

                    return 0.299 * $r + 0.587 * $g + 0.114 * $b;
                },
                $row,
            ),
            $rgbMatrix,
        );
    }

    /**
     * @param array<int, array<int, array{0: float, 1: float, 2: float}>> $rgbMatrix
     */
    private function saturationClipping(array $rgbMatrix): ?float
    {
        $bins  = array_fill(0, 101, 0);
        $total = 0;

        foreach ($rgbMatrix as $row) {
            foreach ($row as [$r, $g, $b]) {
                $maxChannel = max($r, $g, $b);
                $minChannel = min($r, $g, $b);

                if ($maxChannel <= 0.0) {
                    $bin = 0;
                } else {
                    $s   = ($maxChannel - $minChannel) / $maxChannel;
                    $bin = (int) round($s * 100.0);
                    if ($bin < 0) {
                        $bin = 0;
                    }

                    if ($bin > 100) {
                        $bin = 100;
                    }
                }

                ++$bins[$bin];
                ++$total;
            }
        }

        if ($total === 0) {
            return null;
        }

        $clipped = 0;
        for ($i = 98; $i <= 100; ++$i) {
            $clipped += $bins[$i];
        }

        return $clipped / $total;
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
