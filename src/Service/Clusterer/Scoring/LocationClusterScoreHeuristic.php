<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function count;

final class LocationClusterScoreHeuristic extends AbstractClusterScoreHeuristic
{
    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $mediaItems = $this->collectMediaItems($cluster, $mediaMap);
        $params     = $cluster->getParams();
        $members    = count($cluster->getMembers());

        $metrics = $this->computeLocationMetrics($mediaItems, $members, $params);

        $cluster->setParam('location_score', $metrics['score']);
        $cluster->setParam('location_geo_coverage', $metrics['geo_coverage']);
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['location_score'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'location';
    }

    /**
     * @param list<Media>         $mediaItems
     * @param int                 $members
     * @param array<string,mixed> $params
     *
     * @return array{score:float,geo_coverage:float}
     */
    private function computeLocationMetrics(array $mediaItems, int $members, array $params): array
    {
        if (isset($params['location_score']) && $this->floatOrNull($params['location_score']) !== null) {
            return [
                'score'        => $this->clamp01((float) $params['location_score']),
                'geo_coverage' => $this->clamp01($this->floatOrNull($params['location_geo_coverage'] ?? null)),
            ];
        }

        $coords = [];
        foreach ($mediaItems as $media) {
            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();
            if ($lat === null || $lon === null) {
                continue;
            }

            $coords[] = [(float) $lat, (float) $lon];
        }

        $withGeo  = count($coords);
        $coverage = $members > 0 ? $withGeo / $members : 0.0;
        $spread   = 0.0;

        $n = count($coords);
        if ($n > 1) {
            $centroidLat = 0.0;
            $centroidLon = 0.0;
            foreach ($coords as $coord) {
                $centroidLat += $coord[0];
                $centroidLon += $coord[1];
            }

            $centroidLat /= $n;
            $centroidLon /= $n;

            $maxDistance = 0.0;
            foreach ($coords as $coord) {
                $distance = MediaMath::haversineDistanceInMeters(
                    $centroidLat,
                    $centroidLon,
                    $coord[0],
                    $coord[1]
                );

                if ($distance > $maxDistance) {
                    $maxDistance = $distance;
                }
            }

            $spread = $maxDistance;
        }

        $compactness = $spread === 0.0 ? 1.0 : $this->clamp01(1.0 - min(1.0, $spread / 10_000.0));

        $score = $this->combineScores([
            [$coverage, 0.7],
            [$compactness, 0.3],
        ], 0.0);

        return [
            'score'        => $score,
            'geo_coverage' => $coverage,
        ];
    }
}
