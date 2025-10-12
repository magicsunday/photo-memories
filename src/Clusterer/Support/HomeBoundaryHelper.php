<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_filter;
use function array_values;
use function is_array;
use function is_int;

/**
 * Utility helpers for evaluating proximity to configured home centers.
 */
final class HomeBoundaryHelper
{
    /**
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int,valid_from?:int|null,valid_until?:int|null}>} $home
     *
     * @return list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int,valid_from?:int|null,valid_until?:int|null}>
     */
    public static function centers(array $home, ?int $timestamp = null): array
    {
        $centers = $home['centers'] ?? null;

        if (is_array($centers) && $centers !== []) {
            if ($timestamp === null) {
                return $centers;
            }

            $filtered = array_values(array_filter(
                $centers,
                static function (array $center) use ($timestamp): bool {
                    $from  = $center['valid_from'] ?? null;
                    $until = $center['valid_until'] ?? null;

                    if ($from !== null && is_int($from) && $timestamp < $from) {
                        return false;
                    }

                    if ($until !== null && is_int($until) && $timestamp > $until) {
                        return false;
                    }

                    return true;
                }
            ));

            if ($filtered !== []) {
                return $filtered;
            }

            return $centers;
        }

        return [[
            'lat'             => $home['lat'],
            'lon'             => $home['lon'],
            'radius_km'       => $home['radius_km'],
            'country'         => $home['country'] ?? null,
            'timezone_offset' => $home['timezone_offset'] ?? null,
            'valid_from'      => null,
            'valid_until'     => null,
        ]];
    }

    /**
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int,valid_from?:int|null,valid_until?:int|null}>} $home
     *
     * @return array{distance_km:float,radius_km:float,center:array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int,valid_from?:int|null,valid_until?:int|null},index:int}
     */
    public static function nearestCenter(array $home, float $lat, float $lon, ?int $timestamp = null): array
    {
        $centers    = self::centers($home, $timestamp);
        $bestIndex  = 0;
        $bestCenter = $centers[0];
        $bestDist   = PHP_FLOAT_MAX;

        foreach ($centers as $index => $center) {
            $distance = MediaMath::haversineDistanceInMeters(
                $lat,
                $lon,
                (float) $center['lat'],
                (float) $center['lon'],
            ) / 1000.0;

            if ($distance < $bestDist) {
                $bestDist   = $distance;
                $bestCenter = $center;
                $bestIndex  = $index;
            }
        }

        return [
            'distance_km' => $bestDist,
            'radius_km'   => (float) $bestCenter['radius_km'],
            'center'      => $bestCenter,
            'index'       => $bestIndex,
        ];
    }

    /**
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,valid_from?:int|null,valid_until?:int|null}>} $home
     */
    public static function isBeyondHome(
        array $home,
        float $lat,
        float $lon,
        bool $treatSecondaryCentersAsHome = false,
        ?int $timestamp = null,
    ): bool {
        $nearest = self::nearestCenter($home, $lat, $lon, $timestamp);

        if ($nearest['index'] > 0) {
            if ($treatSecondaryCentersAsHome === false) {
                return true;
            }

            $center       = $nearest['center'];
            $memberCount  = (int) ($center['member_count'] ?? 0);
            $dwellSeconds = (int) ($center['dwell_seconds'] ?? 0);

            if ($memberCount > 0 || $dwellSeconds > 0) {
                return true;
            }
        }

        return $nearest['distance_km'] > $nearest['radius_km'];
    }

    /**
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,valid_from?:int|null,valid_until?:int|null}>} $home
     */
    public static function primaryRadius(array $home, ?int $timestamp = null): float
    {
        $centers = self::centers($home, $timestamp);

        return (float) $centers[0]['radius_km'];
    }

    /**
     * @param list<Media> $members
     */
    public static function hasCoordinateSamples(array $members): bool
    {
        foreach ($members as $media) {
            if ($media->getGpsLat() !== null && $media->getGpsLon() !== null) {
                return true;
            }
        }

        return false;
    }
}
