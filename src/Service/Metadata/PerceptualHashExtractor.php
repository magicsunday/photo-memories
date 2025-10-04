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
use MagicSunday\Memories\Service\Metadata\Support\GdImageToolsTrait;
use MagicSunday\Memories\Service\Metadata\Support\ImageAdapterInterface;

use function acos;
use function array_fill;
use function array_shift;
use function bindec;
use function ceil;
use function cos;
use function count;
use function dechex;
use function floor;
use function hex2bin;
use function is_file;
use function is_string;
use function min;
use function sort;
use function sprintf;
use function sqrt;
use function str_pad;
use function str_starts_with;
use function strlen;
use function substr;
use function unpack;

use const SORT_NUMERIC;
use const STR_PAD_RIGHT;

/**
 * Computes perceptual hashes (pHash 64-bit) plus aHash/dHash from a single
 * downsampled grayscale matrix. Uses a numerically stable 2D-DCT and a
 * proper median threshold (excluding DC) for pHash.
 */
final readonly class PerceptualHashExtractor implements SingleMetadataExtractorInterface
{
    use GdImageToolsTrait;

    public function __construct(
        private int $dctSampleSize = 32,   // NxN downsample for DCT (32 recommended)
        private int $lowFreqSize = 8,      // use top-left 8x8 block
    ) {
        if ($this->dctSampleSize < 16 || ($this->dctSampleSize & ($this->dctSampleSize - 1)) !== 0) {
            // Power-of-two helps for cache reuse; 32 is a good compromise (speed/quality).
            throw new InvalidArgumentException('dctSampleSize must be a power of two >= 16');
        }

        if ($this->lowFreqSize < 4 || $this->lowFreqSize > $this->dctSampleSize) {
            throw new InvalidArgumentException('lowFreqSize must be in [4..dctSampleSize]');
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
            // No backend → keep previous behavior, but do not crash
            return $media;
        }

        // One grayscale downsample for all hashes
        $mat = $this->grayscaleMatrixFromAdapter($adapter, $this->dctSampleSize, $this->dctSampleSize);
        $adapter->destroy();

        // pHash 64-bit
        [$phashHex, $phashUint] = $this->computePhash64($mat, $this->lowFreqSize);

        // Optional: derive simple aHash/dHash for QA/Debug (falls Media Felder hat)
        $media->setAhash($this->computeAhash64($mat));
        $media->setDhash($this->computeDhash64($mat));
        $media->setPhash($phashHex);
        $media->setPhashPrefix($phashHex);
        $media->setPhash64($phashUint);

        return $media;
    }

    /**
     * pHash: 2D-DCT on NxN, take top-left kxk, threshold by median (excluding DC),
     * set bit if coefficient > median (includes DC in comparison, classic 64-bit).
     *
     * @param array<int,array<int,float>> $g NxN grayscale in [0..255]
     */
    /**
     * @return array{0: string, 1: string}
     */
    private function computePhash64(array $g, int $k): array
    {
        $n = count($g);
        if ($n < $k || $n !== count($g[0])) {
            return ['0000000000000000', '0'];
        }

        // 1) 2D-DCT (type-II) with orthonormal normalization
        $dct = $this->dct2($g);

        // 2) collect kxk block
        $coeffs   = [];
        $coeffs[] = $dct[0][0]; // DC
        for ($i = 0; $i < $k; ++$i) {
            for ($j = 0; $j < $k; ++$j) {
                if ($i === 0 && $j === 0) {
                    continue; // exclude DC from median set
                }

                $coeffs[] = $dct[$i][$j];
            }
        }

        // 3) median of non-DC coefficients
        $nonDc = $coeffs;
        array_shift($nonDc); // drop DC
        sort($nonDc, SORT_NUMERIC);
        $mIdx   = (int) floor(count($nonDc) / 2);
        $median = (float) ($nonDc[$mIdx] ?? 0.0);

        // 4) build 64-bit bitstring in row-major order over kxk (incl. DC, thresh by median)
        $bits = '';
        for ($i = 0; $i < $k; ++$i) {
            for ($j = 0; $j < $k; ++$j) {
                $c = $dct[$i][$j];
                $bits .= ($c > $median) ? '1' : '0';
            }
        }

        // 5) 64 bits → 16 hex chars + unsigned integer string
        $hex = $this->bitsToHex64($bits);
        $bin = hex2bin($hex);

        if ($bin === false || strlen($bin) !== 8) {
            return [$hex, '0'];
        }

        /** @var array{1:int}|false $packed */
        $packed = unpack('J', $bin);
        $value  = $packed[1] ?? 0;

        return [$hex, sprintf('%u', $value)];
    }

    /**
     * aHash 64-bit on 8x8 average (downscale g to 8x8 by average pooling).
     *
     * @param array<int,array<int,float>> $g
     */
    private function computeAhash64(array $g): string
    {
        $small = $this->resizeMatrixAverage($g, 8, 8);
        $sum   = 0.0;
        $cnt   = 0;
        for ($y = 0; $y < 8; ++$y) {
            for ($x = 0; $x < 8; ++$x) {
                $sum += $small[$y][$x];
                ++$cnt;
            }
        }

        $avg = $sum / (float) $cnt;

        $bits = '';
        for ($y = 0; $y < 8; ++$y) {
            for ($x = 0; $x < 8; ++$x) {
                $bits .= ($small[$y][$x] > $avg) ? '1' : '0';
            }
        }

        return $this->bitsToHex64($bits);
    }

    /**
     * dHash 64-bit (horizontal): 8x9 samples, compare neighbors.
     *
     * @param array<int,array<int,float>> $g
     */
    private function computeDhash64(array $g): string
    {
        $small = $this->resizeMatrixAverage($g, 9, 8); // 9x8 for horizontal diffs
        $bits  = '';
        for ($y = 0; $y < 8; ++$y) {
            for ($x = 0; $x < 8; ++$x) {
                $bits .= ($small[$y][$x] > $small[$y][$x + 1]) ? '1' : '0';
            }
        }

        return $this->bitsToHex64($bits);
    }

    /**
     * Orthonormal DCT-II for an NxN matrix.
     *
     * @param array<int,array<int,float>> $g
     *
     * @return array<int,array<int,float>>
     */
    private function dct2(array $g): array
    {
        $N    = count($g);
        $cosT = $this->cosTable($N);

        // Rows DCT
        $tmp    = array_fill(0, $N, array_fill(0, $N, 0.0));
        $alpha0 = sqrt(1.0 / $N);
        $alpha  = sqrt(2.0 / $N);

        for ($y = 0; $y < $N; ++$y) {
            for ($u = 0; $u < $N; ++$u) {
                $sum = 0.0;
                for ($x = 0; $x < $N; ++$x) {
                    $sum += $g[$y][$x] * $cosT[$u][$x];
                }

                $tmp[$y][$u] = ($u === 0 ? $alpha0 : $alpha) * $sum;
            }
        }

        // Columns DCT
        $out = array_fill(0, $N, array_fill(0, $N, 0.0));
        for ($v = 0; $v < $N; ++$v) {
            for ($u = 0; $u < $N; ++$u) {
                $sum = 0.0;
                for ($y = 0; $y < $N; ++$y) {
                    $sum += $tmp[$y][$u] * $cosT[$v][$y];
                }

                $out[$v][$u] = ($v === 0 ? $alpha0 : $alpha) * $sum;
            }
        }

        return $out;
    }

    /**
     * Cache cosine table cos[(2x+1)uπ/(2N)].
     *
     * @return array<int,array<int,float>>
     */
    private function cosTable(int $N): array
    {
        static $cache = [];
        $key          = (string) $N;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $t  = array_fill(0, $N, array_fill(0, $N, 0.0));
        $pi = acos(-1.0);
        for ($u = 0; $u < $N; ++$u) {
            for ($x = 0; $x < $N; ++$x) {
                $t[$u][$x] = cos(($pi / (2.0 * $N)) * (2.0 * $x + 1.0) * $u);
            }
        }

        return $cache[$key] = $t;
    }

    /**
     * Average pooling resize of a matrix to WxH (fast & stable for hashing).
     *
     * @param array<int,array<int,float>> $g
     *
     * @return array<int,array<int,float>>
     */
    private function resizeMatrixAverage(array $g, int $w, int $h): array
    {
        $H = count($g);
        $W = $H > 0 ? count($g[0]) : 0;
        if ($W < 1 || $H < 1) {
            return array_fill(0, $h, array_fill(0, $w, 0.0));
        }

        $out = array_fill(0, $h, array_fill(0, $w, 0.0));
        $sx  = $W / $w;
        $sy  = $H / $h;

        for ($yy = 0; $yy < $h; ++$yy) {
            $y0 = (int) floor($yy * $sy);
            $y1 = (int) min($H, ceil(($yy + 1) * $sy));
            for ($xx = 0; $xx < $w; ++$xx) {
                $x0  = (int) floor($xx * $sx);
                $x1  = (int) min($W, ceil(($xx + 1) * $sx));
                $sum = 0.0;
                $cnt = 0;
                for ($y = $y0; $y < $y1; ++$y) {
                    for ($x = $x0; $x < $x1; ++$x) {
                        $sum += $g[$y][$x];
                        ++$cnt;
                    }
                }

                $out[$yy][$xx] = $cnt > 0 ? ($sum / (float) $cnt) : 0.0;
            }
        }

        return $out;
    }

    /**
     * Convert 64 bits ('0'/'1') into 16-char hex.
     */
    private function bitsToHex64(string $bits): string
    {
        // ensure length is exactly 64
        if (strlen($bits) !== 64) {
            $bits = strlen($bits) > 64 ? substr($bits, 0, 64) : str_pad($bits, 64, '0', STR_PAD_RIGHT);
        }

        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $hex .= dechex(bindec(substr($bits, $i, 4)));
        }

        return $hex;
    }
}
