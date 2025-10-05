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
use MagicSunday\Memories\Clusterer\Support\HomeBoundaryHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function assert;
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
            if (HomeBoundaryHelper::isBeyondHome($home, $staypointBase['lat'], $staypointBase['lon'])) {
                return $staypointBase;
            }

            if ($sleepProxy !== null && HomeBoundaryHelper::isBeyondHome($home, $sleepProxy['lat'], $sleepProxy['lon'])) {
                return $sleepProxy;
            }

            return $staypointBase;
        }

        if ($sleepProxy !== null) {
            if (HomeBoundaryHelper::isBeyondHome($home, $sleepProxy['lat'], $sleepProxy['lon'])) {
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

        return $this->formatBaseLocation($best['lat'], $best['lon'], 'staypoint', $home);
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
            $lastCoords   = $this->mediaCoordinates($last);
            $nextCoords   = $this->mediaCoordinates($nextFirst);
            $lastNearest  = HomeBoundaryHelper::nearestCenter($home, $lastCoords['lat'], $lastCoords['lon']);
            $nextNearest  = HomeBoundaryHelper::nearestCenter($home, $nextCoords['lat'], $nextCoords['lon']);
            $pairDistance = MediaMath::haversineDistanceInMeters(
                $lastCoords['lat'],
                $lastCoords['lon'],
                $nextCoords['lat'],
                $nextCoords['lon'],
            ) / 1000.0;

            if (
                $pairDistance <= 2.0
                && $lastNearest['distance_km'] > $lastNearest['radius_km']
                && $nextNearest['distance_km'] > $nextNearest['radius_km']
            ) {
                $lat = ($lastCoords['lat'] + $nextCoords['lat']) / 2.0;
                $lon = ($lastCoords['lon'] + $nextCoords['lon']) / 2.0;

                return $this->formatBaseLocation($lat, $lon, 'sleep_proxy_pair', $home);
            }

            if ($lastNearest['distance_km'] > $nextNearest['distance_km']) {
                return $this->formatBaseLocation($lastCoords['lat'], $lastCoords['lon'], 'sleep_proxy_last', $home);
            }

            return $this->formatBaseLocation($nextCoords['lat'], $nextCoords['lon'], 'sleep_proxy_first', $home);
        }

        if ($last instanceof Media) {
            $coords = $this->mediaCoordinates($last);

            return $this->formatBaseLocation($coords['lat'], $coords['lon'], 'sleep_proxy_last', $home);
        }

        if ($nextFirst instanceof Media) {
            $coords = $this->mediaCoordinates($nextFirst);

            return $this->formatBaseLocation($coords['lat'], $coords['lon'], 'sleep_proxy_first', $home);
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

        return $this->formatBaseLocation($best['lat'], $best['lon'], 'staypoint', $home);
    }

    private function fallbackBaseLocation(array $summary, array $home): ?array
    {
        $gpsMembers = $summary['gpsMembers'];
        if ($gpsMembers === []) {
            return null;
        }

        $centroid = MediaMath::centroid($gpsMembers);

        return $this->formatBaseLocation($centroid['lat'], $centroid['lon'], 'day_centroid', $home);
    }

    private function formatBaseLocation(float $lat, float $lon, string $source, array $home): array
    {
        $nearest = HomeBoundaryHelper::nearestCenter($home, $lat, $lon);

        return [
            'lat'         => $lat,
            'lon'         => $lon,
            'distance_km' => $nearest['distance_km'],
            'source'      => $source,
        ];
    }
}
