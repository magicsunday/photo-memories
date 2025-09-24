<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;

/**
 * Helper for deriving location keys and display labels from Location entities.
 */
final class LocationHelper
{
    /**
     * Returns a stable locality key for grouping.
     * Priority: suburb -> city -> county -> state -> country -> cell.
     */
    public function localityKey(?Location $loc): ?string
    {
        if (!$loc instanceof Location) {
            return null;
        }

        $parts = [];
        $suburb = $loc->getSuburb();
        $city   = $loc->getCity();
        $county = $loc->getCounty();
        $state  = $loc->getState();
        $country= $loc->getCountry();
        $cell   = \method_exists($loc, 'getCell') ? $loc->getCell() : null;

        if ($suburb !== null) { $parts[] = 'suburb:'.$suburb; }
        if ($city   !== null) { $parts[] = 'city:'.$city; }
        if ($county !== null) { $parts[] = 'county:'.$county; }
        if ($state  !== null) { $parts[] = 'state:'.$state; }
        if ($country!== null) { $parts[] = 'country:'.$country; }
        if ($parts === [] && $cell !== null) {
            $parts[] = 'cell:'.$cell;
        }

        return $parts !== [] ? \implode('|', $parts) : null;
    }

    /**
     * Short human label for titles.
     * Prefers "Suburb, City" -> "City" -> "County" -> "State" -> "Country".
     */
    public function displayLabel(?Location $loc): ?string
    {
        if (!$loc instanceof Location) {
            return null;
        }

        $city   = $loc->getCity();
        $county = $loc->getCounty();
        $state  = $loc->getState();
        $country= $loc->getCountry();

        if ($city !== null) {
            return $city;
        }
        if ($county !== null) {
            return $county;
        }
        if ($state !== null) {
            return $state;
        }
        if ($country !== null) {
            return $country;
        }
        return null;
    }

    /**
     * Convenience wrappers reading from Media directly.
     */
    public function localityKeyForMedia(Media $m): ?string
    {
        return $this->localityKey($m->getLocation());
    }

    public function labelForMedia(Media $m): ?string
    {
        return $this->displayLabel($m->getLocation());
    }

    /**
     * Returns the majority location label across members (stable for titles).
     *
     * @param list<Media> $members
     */
    public function majorityLabel(array $members): ?string
    {
        /** @var array<string,int> $count */
        $count = [];
        foreach ($members as $m) {
            $label = $this->labelForMedia($m);
            if ($label !== null) {
                $count[$label] = ($count[$label] ?? 0) + 1;
            }
        }
        if ($count === []) {
            return null;
        }
        \arsort($count, \SORT_NUMERIC);
        return \array_key_first($count);
    }

    /**
     * Returns true if two medias share the same locality key.
     */
    public function sameLocality(Media $a, Media $b): bool
    {
        $ka = $this->localityKeyForMedia($a);
        $kb = $this->localityKeyForMedia($b);
        return $ka !== null && $kb !== null && $ka === $kb;
    }
}
