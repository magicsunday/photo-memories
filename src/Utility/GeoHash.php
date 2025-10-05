<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use InvalidArgumentException;

/**
 * Helper for encoding latitude/longitude into GeoHash strings.
 */
final class GeoHash
{
    private const string BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    /**
     * Encodes the provided latitude and longitude into a GeoHash.
     *
     * @param float $latitude  Latitude in decimal degrees.
     * @param float $longitude Longitude in decimal degrees.
     * @param int   $precision Desired GeoHash length.
     */
    public static function encode(float $latitude, float $longitude, int $precision): string
    {
        if ($precision <= 0) {
            throw new InvalidArgumentException('GeoHash precision must be a positive integer.');
        }

        if ($latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException('Latitude must be within the range -90 to 90 degrees.');
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException('Longitude must be within the range -180 to 180 degrees.');
        }

        $geohash = '';
        $latInterval = [-90.0, 90.0];
        $lonInterval = [-180.0, 180.0];
        $isEven = true;
        $bit = 0;
        $ch = 0;

        while (strlen($geohash) < $precision) {
            if ($isEven) {
                $mid = ($lonInterval[0] + $lonInterval[1]) / 2.0;
                if ($longitude >= $mid) {
                    $ch |= 1 << (4 - $bit);
                    $lonInterval[0] = $mid;
                } else {
                    $lonInterval[1] = $mid;
                }
            } else {
                $mid = ($latInterval[0] + $latInterval[1]) / 2.0;
                if ($latitude >= $mid) {
                    $ch |= 1 << (4 - $bit);
                    $latInterval[0] = $mid;
                } else {
                    $latInterval[1] = $mid;
                }
            }

            $isEven = !$isEven;

            if ($bit < 4) {
                $bit++;
                continue;
            }

            $geohash .= self::BASE32[$ch];
            $bit = 0;
            $ch = 0;
        }

        return $geohash;
    }
}
