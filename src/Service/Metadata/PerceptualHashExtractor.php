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
use MagicSunday\Memories\Service\Metadata\Support\VideoPosterFrameTrait;

use function acos;
use function array_fill;
use function array_shift;
use function ceil;
use function cos;
use function count;
use function floor;
use function hex2bin;
use function is_file;
use function is_string;
use function max;
use function min;
use function sort;
use function sprintf;
use function sqrt;
use function str_pad;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function unpack;

use const SORT_NUMERIC;
use const STR_PAD_RIGHT;

/**
 * Computes perceptual hashes (pHash 128-bit) plus aHash/dHash from a single
 * downsampled grayscale matrix. Uses a numerically stable 2D-DCT and a
 * proper median threshold (excluding DC) for pHash. Supports poster frame
 * extraction for video assets via ffmpeg/ffprobe.
 */
final readonly class PerceptualHashExtractor implements SingleMetadataExtractorInterface
{
    use GdImageToolsTrait;
    use VideoPosterFrameTrait;

    public function __construct(
        private int $dctSampleSize = 32,
        private int $lowFreqSize = 16,
        private int $phashPrefixLength = 16,
        private string $ffmpegBinary = 'ffmpeg',
        private string $ffprobeBinary = 'ffprobe',
        private float $posterFrameSecond = 1.0,
    ) {
        if ($this->dctSampleSize < 16 || ($this->dctSampleSize & ($this->dctSampleSize - 1)) !== 0) {
            throw new InvalidArgumentException('dctSampleSize must be a power of two >= 16');
        }

        if ($this->lowFreqSize < 12 || $this->lowFreqSize > $this->dctSampleSize) {
            throw new InvalidArgumentException('lowFreqSize must be in [12..dctSampleSize] for 128-bit pHash');
        }

        if ($this->phashPrefixLength < 0 || $this->phashPrefixLength > 32) {
            throw new InvalidArgumentException('phashPrefixLength must be within [0..32]');
        }

        if ($this->posterFrameSecond < 0.0) {
            throw new InvalidArgumentException('posterFrameSecond must be >= 0');
        }

        if ($this->ffmpegBinary === '') {
            $this->ffmpegBinary = 'ffmpeg';
        }

        if ($this->ffprobeBinary === '') {
            $this->ffprobeBinary = 'ffprobe';
        }
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
            if (!is_string($posterPath) || !is_file($posterPath)) {
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

            $mat = $this->grayscaleMatrixFromAdapter($adapter, $this->dctSampleSize, $this->dctSampleSize);
            $adapter->destroy();

            [$phashHex, $phash64] = $this->computePhash128($mat, $this->lowFreqSize);

            $media->setAhash($this->computeAhash64($mat));
            $media->setDhash($this->computeDhash64($mat));
            $media->setPhash(strtolower($phashHex));

            $prefixLength = max(0, min(32, $this->phashPrefixLength));
            if ($prefixLength > 0) {
                $media->setPhashPrefix(substr(strtolower($phashHex), 0, $prefixLength));
            } else {
                $media->setPhashPrefix(null);
            }

            $media->setPhash64($phash64);

            return $media;
        } finally {
            $this->cleanupPosterFrame($posterPath);
        }
    }

    /**
     * @param array<int,array<int,float>> $g
     *
     * @return array{0: string, 1: string}
     */
    private function computePhash128(array $g, int $k): array
    {
        $n = count($g);
        if ($n < 1 || $n !== count($g[0])) {
            return ['00000000000000000000000000000000', '0'];
        }

        $blockSize = min($k, $n);
        if ($blockSize < 4) {
            $blockSize = min(4, $n);
        }

        $bitCount = 128;
        $maxBits  = $blockSize * $blockSize;
        if ($maxBits < $bitCount) {
            $bitCount = $maxBits;
        }

        $dct = $this->dct2($g);

        $coeffs   = [];
        $coeffs[] = $dct[0][0];
        for ($i = 0; $i < $blockSize; ++$i) {
            for ($j = 0; $j < $blockSize; ++$j) {
                if ($i === 0 && $j === 0) {
                    continue;
                }

                $coeffs[] = $dct[$i][$j];
            }
        }

        $nonDc = $coeffs;
        array_shift($nonDc);
        sort($nonDc, SORT_NUMERIC);
        $count  = count($nonDc);
        $median = 0.0;

        if ($count > 0) {
            $mid = (int) floor($count / 2);
            if (($count % 2) === 1) {
                $median = (float) $nonDc[$mid];
            } else {
                $a      = (float) ($nonDc[$mid - 1] ?? 0.0);
                $b      = (float) ($nonDc[$mid] ?? 0.0);
                $median = ($a + $b) / 2.0;
            }
        }

        $bits       = '';
        $positions  = $this->zigzagPositions($blockSize);
        $bitCounter = 0;

        foreach ($positions as [$i, $j]) {
            $bits .= ($dct[$i][$j] > $median) ? '1' : '0';
            ++$bitCounter;

            if ($bitCounter >= $bitCount) {
                break;
            }
        }

        if ($bitCounter < $bitCount) {
            $bits = str_pad($bits, $bitCount, '0', STR_PAD_RIGHT);
        }

        $hex128 = strtolower($this->bitsToHex($bits, $bitCount));

        $bits64 = substr($bits, 0, 64);
        if (strlen($bits64) < 64) {
            $bits64 = str_pad($bits64, 64, '0', STR_PAD_RIGHT);
        }

        $hex64 = strtolower($this->bitsToHex($bits64, 64));
        $bin   = hex2bin($hex64);
        if ($bin === false || strlen($bin) !== 8) {
            return [$hex128, '0'];
        }

        /** @var array{1:int}|false $packed */
        $packed = unpack('J', $bin);
        $value  = $packed[1] ?? 0;

        return [$hex128, sprintf('%u', $value)];
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

        return strtolower($this->bitsToHex($bits, 64));
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

        return strtolower($this->bitsToHex($bits, 64));
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
     * @return array<int,array{0:int,1:int}>
     */
    private function zigzagPositions(int $size): array
    {
        $positions = [];
        $maxIndex  = $size - 1;

        for ($sum = 0; $sum <= 2 * $maxIndex; ++$sum) {
            $minRow = max(0, $sum - $maxIndex);
            $maxRow = min($sum, $maxIndex);

            if (($sum % 2) === 0) {
                for ($row = $maxRow; $row >= $minRow; --$row) {
                    $col          = $sum - $row;
                    $positions[] = [$row, $col];
                }
            } else {
                for ($row = $minRow; $row <= $maxRow; ++$row) {
                    $col          = $sum - $row;
                    $positions[] = [$row, $col];
                }
            }
        }

        return $positions;
    }
}
