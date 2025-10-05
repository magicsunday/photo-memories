<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Service;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Contract\BaseLocationResolverInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function assert;
use function max;
use function usort;

/**
 * Resolves a plausible base location for a vacation day summary.
 */
final class BaseLocationResolver implements BaseLocationResolverInterface
{
    public function resolve(array $summary, ?array $nextSummary, array $home, DateTimeZone $timezone): ?array
    {
        $staypointBase = $this->selectStaypointBase($summary, $nextSummary, $timezone, $home);
        $sleepProxy    = $this->computeSleepProxyLocation($summary, $nextSummary, $home);

        if ($staypointBase !== null) {
            if ($staypointBase['distance_km'] > $home['radius_km']) {
                return $staypointBase;
            }

            if ($sleepProxy !== null && $sleepProxy['distance_km'] > $home['radius_km']) {
                return $sleepProxy;
            }

            return $staypointBase;
        }

        if ($sleepProxy !== null) {
            if ($sleepProxy['distance_km'] > $home['radius_km']) {
                return $sleepProxy;
            }

            $largestStaypoint = $this->selectLargestStaypoint($summary['staypoints'], $home);
            return $largestStaypoint ?? $sleepProxy;
        }

        $largestStaypoint = $this->selectLargestStaypoint($summary['staypoints'], $home);
        return $largestStaypoint ?? $this->fallbackBaseLocation(
            $summary,
            $home
        );
    }

    private function selectStaypointBase(
        array $summary,
        ?array $nextSummary,
        DateTimeZone $timezone,
        array $home,
    ): ?array {
        $windowStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $summary['date'] . ' 18:00:00', $timezone);
        if ($windowStart === false) {
            return null;
        }

        $windowEnd = $windowStart->modify('+16 hours');

        $candidates = [];
        foreach ($summary['staypoints'] as $staypoint) {
            if ($this->staypointOverlapsWindow($staypoint, $windowStart, $windowEnd)) {
                $candidates[] = $staypoint;
            }
        }

        if ($nextSummary !== null) {
            foreach ($nextSummary['staypoints'] as $staypoint) {
                if ($this->staypointOverlapsWindow($staypoint, $windowStart, $windowEnd)) {
                    $candidates[] = $staypoint;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['dwell'] <=> $a['dwell']);
        $best = $candidates[0];

        return [
            'lat'          => $best['lat'],
            'lon'          => $best['lon'],
            'distance_km'  => $this->distanceToHomeKm($best['lat'], $best['lon'], $home),
            'source'       => 'staypoint',
        ];
    }

    private function staypointOverlapsWindow(
        array $staypoint,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): bool {
        return $staypoint['end'] >= $windowStart->getTimestamp()
            && $staypoint['start'] <= $windowEnd->getTimestamp();
    }

    private function computeSleepProxyLocation(array $summary, ?array $nextSummary, array $home): ?array
    {
        $last      = $summary['lastGpsMedia'];
        $nextFirst = $nextSummary['firstGpsMedia'] ?? null;

        if ($last instanceof Media && $nextFirst instanceof Media) {
            $lastCoords = $this->mediaCoordinates($last);
            $nextCoords = $this->mediaCoordinates($nextFirst);

            $pairDistance = MediaMath::haversineDistanceInMeters(
                $lastCoords['lat'],
                $lastCoords['lon'],
                $nextCoords['lat'],
                $nextCoords['lon'],
            ) / 1000.0;

            $lastDistance = $this->distanceToHomeKm($lastCoords['lat'], $lastCoords['lon'], $home);
            $nextDistance = $this->distanceToHomeKm($nextCoords['lat'], $nextCoords['lon'], $home);

            if ($pairDistance <= 2.0 && $lastDistance > $home['radius_km'] && $nextDistance > $home['radius_km']) {
                return [
                    'lat'         => ($lastCoords['lat'] + $nextCoords['lat']) / 2.0,
                    'lon'         => ($lastCoords['lon'] + $nextCoords['lon']) / 2.0,
                    'distance_km' => max($lastDistance, $nextDistance),
                    'source'      => 'sleep_proxy_pair',
                ];
            }

            if ($lastDistance > $nextDistance) {
                return [
                    'lat'         => $lastCoords['lat'],
                    'lon'         => $lastCoords['lon'],
                    'distance_km' => $lastDistance,
                    'source'      => 'sleep_proxy_last',
                ];
            }

            return [
                'lat'         => $nextCoords['lat'],
                'lon'         => $nextCoords['lon'],
                'distance_km' => $nextDistance,
                'source'      => 'sleep_proxy_first',
            ];
        }

        if ($last instanceof Media) {
            $coords = $this->mediaCoordinates($last);

            return [
                'lat'         => $coords['lat'],
                'lon'         => $coords['lon'],
                'distance_km' => $this->distanceToHomeKm($coords['lat'], $coords['lon'], $home),
                'source'      => 'sleep_proxy_last',
            ];
        }

        if ($nextFirst instanceof Media) {
            $coords = $this->mediaCoordinates($nextFirst);

            return [
                'lat'         => $coords['lat'],
                'lon'         => $coords['lon'],
                'distance_km' => $this->distanceToHomeKm($coords['lat'], $coords['lon'], $home),
                'source'      => 'sleep_proxy_first',
            ];
        }

        return null;
    }

    private function mediaCoordinates(Media $media): array
    {
        $lat = $media->getGpsLat();
        $lon = $media->getGpsLon();
        assert($lat !== null && $lon !== null);

        return [
            'lat' => $lat,
            'lon' => $lon,
        ];
    }

    private function selectLargestStaypoint(array $staypoints, array $home): ?array
    {
        if ($staypoints === []) {
            return null;
        }

        usort($staypoints, static fn (array $a, array $b): int => $b['dwell'] <=> $a['dwell']);
        $best = $staypoints[0];

        return [
            'lat'         => $best['lat'],
            'lon'         => $best['lon'],
            'distance_km' => $this->distanceToHomeKm($best['lat'], $best['lon'], $home),
            'source'      => 'staypoint',
        ];
    }

    private function fallbackBaseLocation(array $summary, array $home): ?array
    {
        $gpsMembers = $summary['gpsMembers'];
        if ($gpsMembers === []) {
            return null;
        }

        $centroid = MediaMath::centroid($gpsMembers);

        return [
            'lat'         => $centroid['lat'],
            'lon'         => $centroid['lon'],
            'distance_km' => $this->distanceToHomeKm($centroid['lat'], $centroid['lon'], $home),
            'source'      => 'day_centroid',
        ];
    }

    private function distanceToHomeKm(float $lat, float $lon, array $home): float
    {
        return MediaMath::haversineDistanceInMeters(
            $lat,
            $lon,
            $home['lat'],
            $home['lon'],
        ) / 1000.0;
    }
}
