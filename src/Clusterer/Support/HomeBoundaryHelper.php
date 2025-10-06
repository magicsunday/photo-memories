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

use function is_array;

/**
 * Utility helpers for evaluating proximity to configured home centers.
 */
final class HomeBoundaryHelper
{
    /**
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int}>} $home
     *
     * @return list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int}>
     */
    public static function centers(array $home): array
    {
        $centers = $home['centers'] ?? null;

        if (is_array($centers) && $centers !== []) {
            return $centers;
        }

        return [[
            'lat'             => $home['lat'],
            'lon'             => $home['lon'],
            'radius_km'       => $home['radius_km'],
            'country'         => $home['country'] ?? null,
            'timezone_offset' => $home['timezone_offset'] ?? null,
        ]];
    }

    /**
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int}>} $home
     *
     * @return array{distance_km:float,radius_km:float,center:array{lat:float,lon:float,radius_km:float,country?:string|null,timezone_offset?:int|null,member_count?:int,dwell_seconds?:int},index:int}
     */
    public static function nearestCenter(array $home, float $lat, float $lon): array
    {
        $centers    = self::centers($home);
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
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float}>} $home
     */
    public static function isBeyondHome(array $home, float $lat, float $lon, bool $treatSecondaryCentersAsHome = false): bool
    {
        $nearest = self::nearestCenter($home, $lat, $lon);

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
     * @param array{lat:float,lon:float,radius_km:float,centers?:list<array{lat:float,lon:float,radius_km:float}>} $home
     */
    public static function primaryRadius(array $home): float
    {
        $centers = self::centers($home);

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
