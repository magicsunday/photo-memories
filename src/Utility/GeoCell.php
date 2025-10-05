<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use function is_finite;
use function max;
use function min;
use function round;
use function sprintf;

/**
 * Generates coarse geo cell identifiers without relying on external libraries.
 */
final class GeoCell
{
    private const int DEFAULT_PRECISION = 4;

    private function __construct()
    {
    }

    public static function fromPoint(float $lat, float $lon, int $precision = self::DEFAULT_PRECISION): string
    {
        if (!is_finite($lat) || !is_finite($lon)) {
            return '0.0000,0.0000';
        }

        $precision = max(1, min(7, $precision));

        $lat = self::clampLatitude($lat);
        $lon = self::normalizeLongitude($lon);

        $format = sprintf('%%0.%df,%%0.%df', $precision, $precision);

        return sprintf($format, round($lat, $precision), round($lon, $precision));
    }

    private static function clampLatitude(float $lat): float
    {
        if ($lat > 90.0) {
            return 90.0;
        }

        if ($lat < -90.0) {
            return -90.0;
        }

        return $lat;
    }

    private static function normalizeLongitude(float $lon): float
    {
        if ($lon >= -180.0 && $lon <= 180.0) {
            return $lon;
        }

        $period     = 360.0;
        $normalized = $lon;
        while ($normalized > 180.0) {
            $normalized -= $period;
        }

        while ($normalized < -180.0) {
            $normalized += $period;
        }

        return $normalized;
    }
}
