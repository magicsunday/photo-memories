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

use function array_fill;
use function array_pop;
use function count;
use function in_array;

/**
 * Implements a simple DBSCAN variant with Haversine distances.
 */
final class GeoDbscanHelper
{
    /**
     * @param list<Media> $items
     *
     * @return array{clusters: list<list<Media>>, noise: list<Media>}
     */
    public function clusterMedia(array $items, float $epsKm = 0.1, int $minSamples = 3): array
    {
        if ($items === [] || $epsKm <= 0.0) {
            return ['clusters' => [], 'noise' => $items];
        }

        if ($minSamples < 1) {
            $minSamples = 1;
        }

        $points = [];
        foreach ($items as $media) {
            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();

            if ($lat === null || $lon === null) {
                continue;
            }

            $points[] = [
                'lat'   => (float) $lat,
                'lon'   => (float) $lon,
                'media' => $media,
            ];
        }

        $count = count($points);
        if ($count === 0) {
            return ['clusters' => [], 'noise' => []];
        }

        $assignments = array_fill(0, $count, -1);
        $visited     = array_fill(0, $count, false);
        $clusters    = [];
        $clusterId   = -1;

        for ($i = 0; $i < $count; ++$i) {
            if ($visited[$i] === true) {
                continue;
            }

            $visited[$i] = true;
            $neighbors   = $this->regionQuery($points, $i, $epsKm);

            if (count($neighbors) + 1 < $minSamples) {
                continue;
            }

            ++$clusterId;
            $clusters[$clusterId] = [];

            $this->expandCluster(
                $points,
                $i,
                $neighbors,
                $clusterId,
                $epsKm,
                $minSamples,
                $assignments,
                $visited,
                $clusters,
            );
        }

        $clusterList = [];
        foreach ($clusters as $memberList) {
            if ($memberList !== []) {
                $clusterList[] = $memberList;
            }
        }

        $noise = [];
        foreach ($points as $index => $point) {
            if ($assignments[$index] === -1) {
                $noise[] = $point['media'];
            }
        }

        return [
            'clusters' => $clusterList,
            'noise'    => $noise,
        ];
    }

    /**
     * @param list<array{lat: float, lon: float, media: Media}> $points
     *
     * @return list<int>
     */
    private function regionQuery(array $points, int $pointIndex, float $epsKm): array
    {
        $neighbors = [];
        $count     = count($points);
        $origin    = $points[$pointIndex];

        for ($i = 0; $i < $count; ++$i) {
            if ($i === $pointIndex) {
                continue;
            }

            $candidate = $points[$i];
            $distance  = MediaMath::haversineDistanceInMeters(
                $origin['lat'],
                $origin['lon'],
                $candidate['lat'],
                $candidate['lon'],
            );

            if ($distance <= $epsKm * 1000.0) {
                $neighbors[] = $i;
            }
        }

        return $neighbors;
    }

    /**
     * @param list<array{lat: float, lon: float, media: Media}> $points
     * @param list<int>                                         $neighbors
     * @param array<int, int>                                   $assignments
     * @param array<int, bool>                                  $visited
     * @param array<int, list<Media>>                           $clusters
     */
    private function expandCluster(
        array $points,
        int $pointIndex,
        array $neighbors,
        int $clusterId,
        float $epsKm,
        int $minSamples,
        array &$assignments,
        array &$visited,
        array &$clusters,
    ): void {
        $clusters[$clusterId][]   = $points[$pointIndex]['media'];
        $assignments[$pointIndex] = $clusterId;

        while ($neighbors !== []) {
            $neighborIndex = array_pop($neighbors);
            if ($neighborIndex === null) {
                continue;
            }

            if ($visited[$neighborIndex] === false) {
                $visited[$neighborIndex] = true;
                $neighborNeighbors       = $this->regionQuery($points, $neighborIndex, $epsKm);

                if (count($neighborNeighbors) + 1 >= $minSamples) {
                    foreach ($neighborNeighbors as $candidateIndex) {
                        if (in_array($candidateIndex, $neighbors, true) === false) {
                            $neighbors[] = $candidateIndex;
                        }
                    }
                }
            }

            if ($assignments[$neighborIndex] === -1) {
                $assignments[$neighborIndex] = $clusterId;
                $clusters[$clusterId][]      = $points[$neighborIndex]['media'];
            }
        }
    }
}
