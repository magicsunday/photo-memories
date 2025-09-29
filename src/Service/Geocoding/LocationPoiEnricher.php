<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Utility\MediaMath;

use function array_slice;
use function ceil;
use function count;
use function is_array;
use function is_numeric;
use function max;
use function min;

/**
 * Enriches Location entities with nearby Points of Interest using the Overpass API.
 */
final readonly class LocationPoiEnricher
{
    public function __construct(
        private OverpassClient $client,
        private int $radiusMeters = 250,
        private int $maxPois = 15,
        private float $fetchLimitMultiplier = 3.0,
    ) {
    }

    /**
     * Adds POI information to the location, returns true when the network was contacted.
     */
    public function enrich(Location $location, GeocodeResult $geocode): bool
    {
        if ($this->maxPois <= 0) {
            $this->client->consumeLastUsedNetwork();

            return false;
        }

        $radius      = $this->determineRadius($geocode);
        $queryLimit  = $this->determineQueryLimit();
        $pois        = $this->client->fetchPois($geocode->lat, $geocode->lon, $radius, $queryLimit);
        $usedNetwork = $this->client->consumeLastUsedNetwork();

        if ($pois !== []) {
            if ($this->maxPois > 0 && count($pois) > $this->maxPois) {
                $pois = array_slice($pois, 0, $this->maxPois);
            }

            $location->setPois($pois);
        } elseif ($usedNetwork && $location->getPois() === null) {
            // Mark attempted enrichment to avoid hammering the API when no POIs exist.
            $location->setPois([]);
        }

        return $usedNetwork;
    }

    private function determineQueryLimit(): ?int
    {
        if ($this->fetchLimitMultiplier <= 0.0 || $this->maxPois <= 0) {
            return null;
        }

        $limit = (int) ceil($this->maxPois * $this->fetchLimitMultiplier);

        return max($this->maxPois, $limit);
    }

    private function determineRadius(GeocodeResult $geocode): int
    {
        $radius     = $this->radiusMeters;
        $bboxRadius = $this->radiusFromBoundingBox($geocode);

        if ($bboxRadius !== null) {
            return max($radius, $bboxRadius);
        }

        return $radius;
    }

    private function radiusFromBoundingBox(GeocodeResult $geocode): ?int
    {
        $bbox = $geocode->boundingBox;
        if (!is_array($bbox) || count($bbox) !== 4) {
            return null;
        }

        [$south, $north, $west, $east] = $bbox;
        if (!is_numeric($east)) {
            return null;
        }

        $centerLat = $geocode->lat;
        $centerLon = $geocode->lon;

        $distances = [
            MediaMath::haversineDistanceInMeters($centerLat, $centerLon, $north, $centerLon),
            MediaMath::haversineDistanceInMeters($centerLat, $centerLon, $south, $centerLon),
            MediaMath::haversineDistanceInMeters($centerLat, $centerLon, $centerLat, $east),
            MediaMath::haversineDistanceInMeters($centerLat, $centerLon, $centerLat, $west),
        ];

        $radius = (int) ceil(max($distances));

        if ($radius <= 0) {
            return null;
        }

        return max(50, min($radius, 1000));
    }
}
