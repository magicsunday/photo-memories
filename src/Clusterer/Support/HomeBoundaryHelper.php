<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Utility\MediaMath;

use function assert;
use function is_array;
use function max;

/**
 * Utility helpers for dealing with multi-centered home descriptors.
 */
final class HomeBoundaryHelper
{
    /**
     * @param array{
     *     lat: float,
     *     lon: float,
     *     radius_km: float,
     *     country: string|null,
     *     timezone_offset: int|null,
     *     centers?: list<array{lat:float,lon:float,radius_km:float,member_count?:int,dwell_seconds?:int}>
     * } $home
     *
     * @return list<array{lat:float,lon:float,radius_km:float,member_count?:int,dwell_seconds?:int}>
     */
    public static function extractCenters(array $home): array
    {
        $centers = $home['centers'] ?? null;
        if (is_array($centers) && $centers !== []) {
            $normalized = [];
            foreach ($centers as $center) {
                $normalized[] = [
                    'lat'            => $center['lat'],
                    'lon'            => $center['lon'],
                    'radius_km'      => $center['radius_km'],
                    'member_count'   => $center['member_count'] ?? 0,
                    'dwell_seconds'  => $center['dwell_seconds'] ?? 0,
                ];
            }

            return $normalized;
        }

        return [
            [
                'lat'            => $home['lat'],
                'lon'            => $home['lon'],
                'radius_km'      => $home['radius_km'],
                'member_count'   => 0,
                'dwell_seconds'  => 0,
            ],
        ];
    }

    /**
     * @param array{
     *     lat: float,
     *     lon: float,
     *     radius_km: float,
     *     country: string|null,
     *     timezone_offset: int|null,
     *     centers?: list<array{lat:float,lon:float,radius_km:float,member_count?:int,dwell_seconds?:int}>
     * } $home
     */
    public static function minDistanceKm(float $lat, float $lon, array $home): float
    {
        $centers = self::extractCenters($home);
        $minDistance = null;

        foreach ($centers as $center) {
            $distance = MediaMath::haversineDistanceInMeters(
                $lat,
                $lon,
                $center['lat'],
                $center['lon'],
            ) / 1000.0;

            if ($minDistance === null || $distance < $minDistance) {
                $minDistance = $distance;
            }
        }

        assert($minDistance !== null);

        return $minDistance;
    }

    /**
     * @param array{
     *     lat: float,
     *     lon: float,
     *     radius_km: float,
     *     country: string|null,
     *     timezone_offset: int|null,
     *     centers?: list<array{lat:float,lon:float,radius_km:float,member_count?:int,dwell_seconds?:int}>
     * } $home
     */
    public static function isWithinHome(float $lat, float $lon, array $home): bool
    {
        $centers = self::extractCenters($home);
        foreach ($centers as $center) {
            $distance = MediaMath::haversineDistanceInMeters(
                $lat,
                $lon,
                $center['lat'],
                $center['lon'],
            ) / 1000.0;

            if ($distance <= $center['radius_km']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{
     *     lat: float,
     *     lon: float,
     *     radius_km: float,
     *     country: string|null,
     *     timezone_offset: int|null,
     *     centers?: list<array{lat:float,lon:float,radius_km:float,member_count?:int,dwell_seconds?:int}>
     * } $home
     */
    public static function maxRadius(array $home): float
    {
        $centers = self::extractCenters($home);
        $maxRadius = 0.0;

        foreach ($centers as $center) {
            if ($center['radius_km'] > $maxRadius) {
                $maxRadius = $center['radius_km'];
            }
        }

        if ($maxRadius <= 0.0) {
            $maxRadius = $home['radius_km'];
        }

        return $maxRadius;
    }
}
