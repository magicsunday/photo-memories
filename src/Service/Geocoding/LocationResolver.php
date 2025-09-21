<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Location;

/**
 * Finds or creates a Location for a given GeocodeResult.
 * - Strong dedupe by (provider, providerPlaceId) using an in-memory cache.
 * - Soft dedupe by coarse cell + address heuristics.
 * - Re-attaches cached entities after EM clear().
 */
final class LocationResolver
{
    /** @var array<string,Location> provider|placeId -> Location (may be managed or detached) */
    private array $cacheByKey = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly float $cellDeg = 0.01 // ~1.1km
    ) {
    }

    public function findOrCreate(GeocodeResult $g): Location
    {
        $key = $this->key($g->provider, $g->providerPlaceId);

        // 0) In-memory cache first (fast path inside one run/batch)
        if (isset($this->cacheByKey[$key])) {
            $loc = $this->cacheByKey[$key];
            // If detached but has an id, re-attach (managed instance)
            if (!$this->em->contains($loc) && $loc->getId() > 0) {
                /** @var Location|null $managed */
                $managed = $this->em->find(Location::class, $loc->getId());
                if ($managed instanceof Location) {
                    $this->cacheByKey[$key] = $managed;
                    return $managed;
                }
                // fall back to cached (new/unflushed) instance
            }
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
                return $cand;
            }
        }

        // 3) Create new (managed) entity and cache under exact key
        $loc = new Location(
            provider:        $g->provider,
            providerPlaceId: $g->providerPlaceId,
            displayName:     $g->displayName,
            lat:             $g->lat,
            lon:             $g->lon,
            cell:            $cell
        );

        $loc->setCountryCode($g->countryCode);
        $loc->setCountry($g->country);
        $loc->setState($g->state);
        $loc->setCounty($g->county);
        $loc->setCity($g->city ?? $g->town ?? $g->village);
        $loc->setSuburb($g->suburb ?? $g->neighbourhood);
        $loc->setPostcode($g->postcode);
        $loc->setRoad($g->road);
        $loc->setHouseNumber($g->houseNumber);
        $loc->setCategory($g->category);
        $loc->setType($g->type);
        $loc->setBoundingBox($g->boundingBox);

        $this->em->persist($loc);
        $this->cacheByKey[$key] = $loc; // verhindert Doppel-Persist im selben Batch

        // flush außerhalb (Command)
        return $loc;
    }

    private function key(string $provider, string $providerPlaceId): string
    {
        // normalize to avoid accidental duplicates
        return \strtolower($provider) . '|' . $providerPlaceId;
    }

    private function cellKey(float $lat, float $lon, float $deg): string
    {
        $rlat = $deg * \floor($lat / $deg);
        $rlon = $deg * \floor($lon / $deg);
        return \sprintf('%.4f,%.4f', $rlat, $rlon);
    }

    private function similar(Location $loc, GeocodeResult $g): bool
    {
        $score = 0;
        if ($loc->getCountryCode() !== null && $g->countryCode !== null && $loc->getCountryCode() === \strtoupper($g->countryCode)) { $score++; }
        $gc = $g->city ?? $g->town ?? $g->village;
        if ($loc->getCity() !== null && $gc !== null && $loc->getCity() === $gc) { $score++; }
        if ($loc->getRoad() !== null && $g->road !== null && $loc->getRoad() === $g->road) { $score++; }
        return $score >= 2;
    }
}
