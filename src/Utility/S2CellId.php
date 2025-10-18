<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use function abs;
use function cos;
use function deg2rad;
use function floor;
use function is_finite;
use function max;
use function min;
use function sin;
use function sqrt;
use function sprintf;
use function strtoupper;

/**
 * Minimal S2 cell identifier generator using the canonical Hilbert curve projection.
 */
final class S2CellId
{
    private const int MAX_LEVEL = 30;

    private const int POS_BITS = 2 * self::MAX_LEVEL + 1;

    private function __construct()
    {
    }

    public static function tokenFromDegrees(float $lat, float $lon, int $level = 12): string
    {
        if (!is_finite($lat) || !is_finite($lon)) {
            return '0000000000000000';
        }

        $level = max(0, min(self::MAX_LEVEL, $level));

        $phi   = deg2rad($lat);
        $theta = deg2rad($lon);

        $cosPhi = cos($phi);
        $x      = $cosPhi * cos($theta);
        $y      = $cosPhi * sin($theta);
        $z      = sin($phi);

        [$face, $u, $v] = self::xyzToFaceUv($x, $y, $z);

        $s = self::uvToSt($u);
        $t = self::uvToSt($v);

        $i = self::stToIj($s, self::MAX_LEVEL);
        $j = self::stToIj($t, self::MAX_LEVEL);

        $hilbert = self::xyToHilbert(1 << self::MAX_LEVEL, $i, $j);

        $id = ((($face << self::POS_BITS) | ($hilbert << 1) | 1));

        $shift = 2 * (self::MAX_LEVEL - $level);
        if ($shift > 0) {
            $id = ($id >> $shift) << $shift;
        }

        return strtoupper(sprintf('%016x', $id));
    }

    /**
     * @return array{0:int,1:float,2:float}
     */
    private static function xyzToFaceUv(float $x, float $y, float $z): array
    {
        $absX = abs($x);
        $absY = abs($y);
        $absZ = abs($z);

        if ($absX >= $absY && $absX >= $absZ) {
            $face = $x >= 0.0 ? 0 : 3;
            $u    = $y / $absX * ($x >= 0.0 ? 1 : -1);
            $v    = $z / $absX * ($x >= 0.0 ? 1 : -1);

            return [$face, $u, $v];
        }

        if ($absY >= $absZ) {
            $face = $y >= 0.0 ? 1 : 4;
            $u    = -$x / $absY * ($y >= 0.0 ? 1 : -1);
            $v    = $z / $absY * ($y >= 0.0 ? 1 : -1);

            return [$face, $u, $v];
        }

        $face = $z >= 0.0 ? 2 : 5;
        $u    = -$x / $absZ * ($z >= 0.0 ? 1 : -1);
        $v    = -$y / $absZ * ($z >= 0.0 ? 1 : -1);

        return [$face, $u, $v];
    }

    private static function uvToSt(float $uv): float
    {
        if ($uv >= 0.0) {
            return 0.5 * sqrt(1.0 + 3.0 * $uv);
        }

        return 1.0 - 0.5 * sqrt(1.0 - 3.0 * $uv);
    }

    private static function stToIj(float $s, int $level): int
    {
        $scale = 1 << $level;

        $value = (int) floor($s * $scale);
        if ($value < 0) {
            return 0;
        }

        if ($value >= $scale) {
            return $scale - 1;
        }

        return $value;
    }

    private static function xyToHilbert(int $n, int $x, int $y): int
    {
        $d = 0;

        for ($s = $n >> 1; $s > 0; $s >>= 1) {
            $rx = ($x & $s) !== 0 ? 1 : 0;
            $ry = ($y & $s) !== 0 ? 1 : 0;

            $d += $s * $s * ((3 * $rx) ^ $ry);
            [$x, $y] = self::rotate($s, $x, $y, $rx, $ry);
        }

        return $d;
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function rotate(int $n, int $x, int $y, int $rx, int $ry): array
    {
        if ($ry === 0) {
            if ($rx === 1) {
                $x = $n - 1 - $x;
                $y = $n - 1 - $y;
            }

            return [$y, $x];
        }

        return [$x, $y];
    }
}
