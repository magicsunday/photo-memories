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
use MagicSunday\Memories\Clusterer\Contract\StaypointDetectorInterface;
use MagicSunday\Memories\Utility\MediaMath;

use function array_slice;
use function count;

/**
 * Default staypoint detection implementation.
 */
final class StaypointDetector implements StaypointDetectorInterface
{
    public function detect(array $gpsMembers): array
    {
        $count = count($gpsMembers);
        if ($count < 2) {
            return [];
        }

        $staypoints = [];
        $i = 0;

        while ($i < $count - 1) {
            $startMedia = $gpsMembers[$i];
            $startTime  = $startMedia->getTakenAt();

            if (!$startTime instanceof DateTimeImmutable) {
                ++$i;
                continue;
            }

            $j = $i + 1;
            while ($j < $count) {
                $candidate     = $gpsMembers[$j];
                $candidateTime = $candidate->getTakenAt();

                if (!$candidateTime instanceof DateTimeImmutable) {
                    ++$j;
                    continue;
                }

                $distanceKm = MediaMath::haversineDistanceInMeters(
                    (float) $startMedia->getGpsLat(),
                    (float) $startMedia->getGpsLon(),
                    (float) $candidate->getGpsLat(),
                    (float) $candidate->getGpsLon(),
                ) / 1000.0;

                if ($distanceKm > 0.2) {
                    break;
                }

                ++$j;
            }

            $endIndex = $j - 1;
            if ($endIndex <= $i) {
                ++$i;
                continue;
            }

            $segment = array_slice($gpsMembers, $i, $endIndex - $i + 1);
            $endMedia = $segment[count($segment) - 1];
            $endTime  = $endMedia->getTakenAt();

            if (!$endTime instanceof DateTimeImmutable) {
                ++$i;
                continue;
            }

            $dwell = $endTime->getTimestamp() - $startTime->getTimestamp();
            if ($dwell >= 3600) {
                $centroid = MediaMath::centroid($segment);

                $staypoints[] = [
                    'lat'   => (float) $centroid['lat'],
                    'lon'   => (float) $centroid['lon'],
                    'start' => $startTime->getTimestamp(),
                    'end'   => $endTime->getTimestamp(),
                    'dwell' => $dwell,
                ];

                $i = $endIndex + 1;
                continue;
            }

            ++$i;
        }

        return $staypoints;
    }
}
