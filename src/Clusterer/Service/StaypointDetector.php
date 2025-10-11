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
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\StaypointDetectorInterface;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_slice;
use function count;
use function max;
use function usort;

/**
 * Default staypoint detection implementation.
 */
final readonly class StaypointDetector implements StaypointDetectorInterface
{
    public function __construct(
        private float $staypointRadiusKm = 0.25,
        private int $minDwellMinutes = 20,
        private ?GeoDbscanHelper $dbscanHelper = null,
        private float $fallbackRadiusKm = 0.18,
        private int $fallbackMinSamples = 3,
        private int $fallbackMinDwellMinutes = 20,
    ) {
        if ($this->staypointRadiusKm <= 0.0) {
            throw new InvalidArgumentException('staypointRadiusKm must be greater than zero.');
        }

        if ($this->minDwellMinutes < 1) {
            throw new InvalidArgumentException('minDwellMinutes must be at least one minute.');
        }

        if ($this->fallbackRadiusKm < 0.0) {
            throw new InvalidArgumentException('fallbackRadiusKm must not be negative.');
        }

        if ($this->fallbackMinSamples < 1) {
            throw new InvalidArgumentException('fallbackMinSamples must be positive.');
        }

        if ($this->fallbackMinDwellMinutes < 0) {
            throw new InvalidArgumentException('fallbackMinDwellMinutes must not be negative.');
        }
    }

    public function detect(array $gpsMembers): array
    {
        $sequential = $this->detectSequentialStaypoints($gpsMembers);
        if ($sequential !== []) {
            return $sequential;
        }

        return $this->detectWithDbscanFallback($gpsMembers);
    }

    /**
     * @param list<Media> $gpsMembers
     *
     * @return list<array{lat:float,lon:float,start:int,end:int,dwell:int}>
     */
    private function detectSequentialStaypoints(array $gpsMembers): array
    {
        $count = count($gpsMembers);
        if ($count < 2) {
            return [];
        }

        $staypoints        = [];
        $radiusKm          = $this->staypointRadiusKm;
        $minDwellSeconds   = $this->minDwellMinutes * 60;
        $currentIndex      = 0;

        while ($currentIndex < $count - 1) {
            $startMedia = $gpsMembers[$currentIndex];
            $startTime  = $startMedia->getTakenAt();
            $startLat   = $startMedia->getGpsLat();
            $startLon   = $startMedia->getGpsLon();

            if (!$startTime instanceof DateTimeImmutable || $startLat === null || $startLon === null) {
                ++$currentIndex;
                continue;
            }

            $windowEnd = $currentIndex + 1;
            while ($windowEnd < $count) {
                $candidate     = $gpsMembers[$windowEnd];
                $candidateTime = $candidate->getTakenAt();
                $candLat       = $candidate->getGpsLat();
                $candLon       = $candidate->getGpsLon();

                if (!$candidateTime instanceof DateTimeImmutable || $candLat === null || $candLon === null) {
                    ++$windowEnd;
                    continue;
                }

                $distanceKm = MediaMath::haversineDistanceInMeters(
                    $startLat,
                    $startLon,
                    $candLat,
                    $candLon,
                ) / 1000.0;

                if ($distanceKm > $radiusKm) {
                    break;
                }

                ++$windowEnd;
            }

            $endIndex = $windowEnd - 1;
            if ($endIndex <= $currentIndex) {
                ++$currentIndex;
                continue;
            }

            $segment  = array_slice($gpsMembers, $currentIndex, $endIndex - $currentIndex + 1);
            $endMedia = $segment[count($segment) - 1];
            $endTime  = $endMedia->getTakenAt();

            if (!$endTime instanceof DateTimeImmutable) {
                ++$currentIndex;
                continue;
            }

            $dwell = $endTime->getTimestamp() - $startTime->getTimestamp();
            if ($dwell < $minDwellSeconds) {
                ++$currentIndex;
                continue;
            }

            $centroid = MediaMath::centroid($segment);

            $staypoints[] = [
                'lat'   => $centroid['lat'],
                'lon'   => $centroid['lon'],
                'start' => $startTime->getTimestamp(),
                'end'   => $endTime->getTimestamp(),
                'dwell' => $dwell,
            ];

            $currentIndex = $endIndex + 1;
        }

        return $staypoints;
    }

    /**
     * @param list<Media> $gpsMembers
     *
     * @return list<array{lat:float,lon:float,start:int,end:int,dwell:int}>
     */
    private function detectWithDbscanFallback(array $gpsMembers): array
    {
        if ($this->dbscanHelper === null || $this->fallbackRadiusKm <= 0.0) {
            return [];
        }

        if ($gpsMembers === []) {
            return [];
        }

        $clusters = $this->dbscanHelper->clusterMedia(
            $gpsMembers,
            $this->fallbackRadiusKm,
            max(1, $this->fallbackMinSamples),
        );

        if ($clusters['clusters'] === []) {
            return [];
        }

        $staypoints      = [];
        $minDwellSeconds = $this->fallbackMinDwellMinutes * 60;

        foreach ($clusters['clusters'] as $cluster) {
            if ($cluster === []) {
                continue;
            }

            usort($cluster, static function (Media $a, Media $b): int {
                $left  = $a->getTakenAt();
                $right = $b->getTakenAt();

                $leftTimestamp  = $left instanceof DateTimeImmutable ? $left->getTimestamp() : 0;
                $rightTimestamp = $right instanceof DateTimeImmutable ? $right->getTimestamp() : 0;

                return $leftTimestamp <=> $rightTimestamp;
            });

            $first = $cluster[0];
            $last  = $cluster[count($cluster) - 1];

            $start = $first->getTakenAt();
            $end   = $last->getTakenAt();

            if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
                continue;
            }

            $dwell = $end->getTimestamp() - $start->getTimestamp();
            if ($dwell < $minDwellSeconds) {
                continue;
            }

            $centroid = MediaMath::centroid($cluster);

            $staypoints[] = [
                'lat'   => $centroid['lat'],
                'lon'   => $centroid['lon'],
                'start' => $start->getTimestamp(),
                'end'   => $end->getTimestamp(),
                'dwell' => $dwell,
            ];
        }

        usort($staypoints, static function (array $a, array $b): int {
            return $a['start'] <=> $b['start'];
        });

        return $staypoints;
    }
}
