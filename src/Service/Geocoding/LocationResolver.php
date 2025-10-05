<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use DateTimeImmutable;
use Exception;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Location;

use function floor;
use function sprintf;
use function strtolower;
use function strtoupper;

/**
 * Finds or creates a Location for a given GeocodeResult.
 * - Strong dedupe by (provider, providerPlaceId) using an in-memory cache.
 * - Soft dedupe by coarse cell + address heuristics.
 * - Re-attaches cached entities after EM clear().
 */
final class LocationResolver implements PoiEnsurerInterface
{
    /** @var array<string,Location> provider|placeId -> Location (may be managed or detached) */
    private array $cacheByKey = [];

    private bool $lastUsedNetwork = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly float $cellDeg = 0.01, // ~1.1km
        private readonly ?LocationPoiEnricher $poiEnricher = null,
    ) {
    }

    public function findOrCreate(GeocodeResult $g): Location
    {
        $this->lastUsedNetwork = false;
        $key                   = $this->key($g->provider, $g->providerPlaceId);

        // 0) In-memory cache first (fast path inside one run/batch)
        if (isset($this->cacheByKey[$key])) {
            $loc = $this->cacheByKey[$key];
            $id  = $loc->getId();

            if (!$this->em->contains($loc) && $id !== null && $id > 0) {
                /** @var Location|null $managed */
                $managed = $this->em->find(Location::class, $id);

                if ($managed instanceof Location) {
                    $this->cacheByKey[$key] = $managed;
                    $loc                    = $managed;
                }
            }

            $this->applyGeocodeMetadata($loc, $g);
            $this->maybeEnrich($loc, $g);

            return $loc;
        }

        $repo = $this->em->getRepository(Location::class);

        // 1) Strong dedupe by (provider, providerPlaceId)
        /** @var Location|null $existing */
        $existing = $repo->findOneBy([
            'provider'        => $g->provider,
            'providerPlaceId' => $g->providerPlaceId,
        ]);
        if ($existing instanceof Location) {
            $this->cacheByKey[$key] = $existing;
            $this->applyGeocodeMetadata($existing, $g);
            $this->maybeEnrich($existing, $g);

            return $existing;
        }

        // 2) Soft dedupe by coarse cell + country/city/road heuristic
        $cell = $this->cellKey($g->lat, $g->lon, $this->cellDeg);
        /** @var list<Location> $near */
        $near = $repo->findBy(['cell' => $cell], orderBy: ['id' => 'ASC']);
        foreach ($near as $cand) {
            if ($this->similar($cand, $g)) {
                // absichtlich NICHT unter $key cachen (anderer providerPlaceId),
                // damit wir keinen falschen „alias“ aufbauen.
                $this->applyGeocodeMetadata($cand, $g);
                $this->maybeEnrich($cand, $g);

                return $cand;
            }
        }

        // 3) Create new (managed) entity and cache under exact key
        $loc = new Location(
            provider: $g->provider,
            providerPlaceId: $g->providerPlaceId,
            displayName: $g->displayName,
            lat: $g->lat,
            lon: $g->lon,
            cell: $cell
        );
        $this->applyGeocodeMetadata($loc, $g);

        $this->maybeEnrich($loc, $g);

        $this->em->persist($loc);
        $this->cacheByKey[$key] = $loc; // verhindert Doppel-Persist im selben Batch

        // flush außerhalb (Command)
        return $loc;
    }

    public function consumeLastUsedNetwork(): bool
    {
        $v                     = $this->lastUsedNetwork;
        $this->lastUsedNetwork = false;

        return $v;
    }

    /**
     * Ensures that a previously stored Location eventually receives POI data.
     *
     * This is used when we re-use Locations from the cell cache/index without
     * going through the full reverse-geocoding pipeline again. When
     * $refreshPois is true, existing POI data is cleared before the re-fetch.
     */
    public function ensurePois(Location $location, bool $refreshPois = false): void
    {
        if (!($this->poiEnricher instanceof LocationPoiEnricher)) {
            return;
        }

        if ($refreshPois && $location->getPois() !== null) {
            $location->setPois(null);
        }

        if ($location->getPois() !== null) {
            return;
        }

        $this->enrichWithGeocode($location, $this->geocodeFromLocation($location));
    }

    private function key(string $provider, string $providerPlaceId): string
    {
        // normalize to avoid accidental duplicates
        return strtolower($provider) . '|' . $providerPlaceId;
    }

    private function cellKey(float $lat, float $lon, float $deg): string
    {
        $rlat = $deg * floor($lat / $deg);
        $rlon = $deg * floor($lon / $deg);

        return sprintf('%.4f,%.4f', $rlat, $rlon);
    }

    private function similar(Location $loc, GeocodeResult $g): bool
    {
        $score = 0;
        if ($loc->getCountryCode() !== null && $g->countryCode !== null && $loc->getCountryCode() === strtoupper($g->countryCode)) {
            ++$score;
        }

        $gc = $g->city ?? $g->town ?? $g->village;
        if ($loc->getCity() !== null && $gc !== null && $loc->getCity() === $gc) {
            ++$score;
        }

        if ($loc->getRoad() !== null && $g->road !== null && $loc->getRoad() === $g->road) {
            ++$score;
        }

        return $score >= 2;
    }

    private function maybeEnrich(Location $location, GeocodeResult $geocode): void
    {
        $this->enrichWithGeocode($location, $geocode);
    }

    private function geocodeFromLocation(Location $location): GeocodeResult
    {
        return new GeocodeResult(
            provider: $location->getProvider(),
            providerPlaceId: $location->getProviderPlaceId(),
            lat: $location->getLat(),
            lon: $location->getLon(),
            displayName: $location->getDisplayName(),
            countryCode: $location->getCountryCode(),
            country: $location->getCountry(),
            state: $location->getState(),
            county: $location->getCounty(),
            city: $location->getCity(),
            town: null,
            village: null,
            suburb: $location->getSuburb(),
            neighbourhood: null,
            postcode: $location->getPostcode(),
            road: $location->getRoad(),
            houseNumber: $location->getHouseNumber(),
            boundingBox: $location->getBoundingBox(),
            category: $location->getCategory(),
            type: $location->getType(),
            attribution: $location->getAttribution(),
            licence: $location->getLicence(),
            refreshedAt: $location->getRefreshedAt(),
            confidence: $location->getConfidence(),
            accuracyRadiusMeters: $location->getAccuracyRadiusMeters(),
            timezone: $location->getTimezone(),
            osmType: $location->getOsmType(),
            osmId: $location->getOsmId(),
            wikidataId: $location->getWikidataId(),
            wikipedia: $location->getWikipedia(),
            altNames: $location->getAltNames(),
            extraTags: $location->getExtraTags(),
        );
    }

    private function enrichWithGeocode(Location $location, GeocodeResult $geocode): void
    {
        if (!($this->poiEnricher instanceof LocationPoiEnricher)) {
            return;
        }

        if ($location->getPois() !== null) {
            return;
        }

        $usedNetwork = $this->poiEnricher->enrich($location, $geocode);
        if ($usedNetwork) {
            $this->lastUsedNetwork = true;
        }
    }

    private function applyGeocodeMetadata(Location $location, GeocodeResult $geocode): void
    {
        $location->setDisplayName($geocode->displayName);
        $location->setCountryCode($geocode->countryCode !== null ? strtoupper($geocode->countryCode) : null);
        $location->setCountry($geocode->country);
        $location->setState($geocode->state);
        $location->setCounty($geocode->county);
        $location->setCity($geocode->city ?? $geocode->town ?? $geocode->village);
        $location->setSuburb($geocode->suburb ?? $geocode->neighbourhood);
        $location->setPostcode($geocode->postcode);
        $location->setRoad($geocode->road);
        $location->setHouseNumber($geocode->houseNumber);
        $location->setCategory($geocode->category);
        $location->setType($geocode->type);
        $location->setBoundingBox($geocode->boundingBox);
        $location->setAttribution($geocode->attribution);
        $location->setLicence($geocode->licence);
        $location->setConfidence($geocode->confidence);
        $location->setAccuracyRadiusMeters($geocode->accuracyRadiusMeters);
        $location->setTimezone($geocode->timezone);
        $location->setOsmType($geocode->osmType);
        $location->setOsmId($geocode->osmId);
        $location->setWikidataId($geocode->wikidataId);
        $location->setWikipedia($geocode->wikipedia);
        $location->setAltNames($geocode->altNames);
        $location->setExtraTags($geocode->extraTags);
        $location->setRefreshedAt($this->resolveRefreshedAt($geocode));
        $location->setStale(false);
    }

    private function resolveRefreshedAt(GeocodeResult $geocode): ?DateTimeImmutable
    {
        if ($geocode->refreshedAt instanceof DateTimeImmutable) {
            return $geocode->refreshedAt;
        }

        try {
            return new DateTimeImmutable();
        } catch (Exception) {
            return null;
        }
    }
}
