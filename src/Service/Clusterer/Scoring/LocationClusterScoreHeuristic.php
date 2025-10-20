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
use MagicSunday\Memories\Service\Feed\FeedUserPreferences;
use MagicSunday\Memories\Utility\MediaMath;

use function count;
use function in_array;
use function is_string;
use function mb_strtolower;
use function trim;

/**
 * Class LocationClusterScoreHeuristic.
 */
final class LocationClusterScoreHeuristic extends AbstractClusterScoreHeuristic implements PreferenceAwareClusterScoreHeuristicInterface
{
    public function __construct(private float $favouritePlaceMultiplier = 1.0)
    {
    }

    private ?FeedUserPreferences $preferences = null;

    public function setFeedUserPreferences(?FeedUserPreferences $preferences): void
    {
        $this->preferences = $preferences;
    }

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
        if ($metrics['favourite_match'] > 0.0) {
            $cluster->setParam('location_favourite_match', true);
        }
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
        $cachedLocationScore = $this->floatOrNull($params['location_score'] ?? null);

        if (($score = $cachedLocationScore) !== null) {
            return [
                'score'        => $this->clamp01($score),
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

            $coords[] = [$lat, $lon];
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
        ]);

        $isFavourite = $this->isFavouritePlace($params);
        if ($isFavourite && $this->favouritePlaceMultiplier !== 1.0) {
            $score = $this->clamp01($score * $this->favouritePlaceMultiplier);
        }

        return [
            'score'           => $score,
            'geo_coverage'    => $coverage,
            'favourite_match' => $isFavourite ? 1.0 : 0.0,
        ];
    }

    private function isFavouritePlace(array $params): bool
    {
        if ($this->preferences === null) {
            return false;
        }

        $place = $this->normalisePlace($params['place'] ?? null);
        if ($place === null) {
            return false;
        }

        $favourites = $this->normalisePreferenceList($this->preferences->getFavouritePlaces());

        return in_array($place, $favourites, true);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function normalisePreferenceList(array $values): array
    {
        $normalised = [];

        foreach ($values as $value) {
            $normal = $this->normalisePlace($value);
            if ($normal === null) {
                continue;
            }

            if (!in_array($normal, $normalised, true)) {
                $normalised[] = $normal;
            }
        }

        return $normalised;
    }

    private function normalisePlace(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return mb_strtolower($trimmed);
    }
}
