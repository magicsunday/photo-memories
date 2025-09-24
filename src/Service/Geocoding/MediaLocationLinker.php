<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;

/**
 * Links Media to Locations; uses a pre-warmed cell index + in-run cache.
 */
final class MediaLocationLinker
{
    /** @var array<string,Location> in-run cache: cell -> Location (managed) */
    private array $cellCache = [];

    private bool $lastUsedNetwork = false;

    public function __construct(
        private readonly ReverseGeocoderInterface $geocoder,
        private readonly LocationResolver $resolver,
        private readonly LocationCellIndex $cellIndex,
        private readonly float $cellDeg = 0.01
    ) {
    }

    public function link(Media $media, string $acceptLanguage = 'de'): ?Location
    {
        $this->lastUsedNetwork = false;

        $lat = $media->getGpsLat();
        $lon = $media->getGpsLon();
        if ($lat === null || $lon === null) {
            return null;
        }

        $cell = $this->cellKey($lat, $lon, $this->cellDeg);

        // 1) in-run cache first
        if (isset($this->cellCache[$cell])) {
            $loc = $this->cellCache[$cell];
            $media->setLocation($loc);
            return $loc;
        }

        // 2) pre-warmed DB index
        $fromIndex = $this->cellIndex->findByCell($cell);
        if ($fromIndex instanceof Location) {
            $this->cellCache[$cell] = $fromIndex;
            $media->setLocation($fromIndex);
            return $fromIndex;
        }

        // 3) network path once per cell
        $result = $this->geocoder->reverse($lat, $lon, $acceptLanguage);
        if ($result === null) {
            return null;
        }

        $loc = $this->resolver->findOrCreate($result);
        $this->cellCache[$cell] = $loc;
        $this->cellIndex->remember($cell, $loc);
        $this->lastUsedNetwork = true;

        $media->setLocation($loc);
        return $loc;
    }

    public function consumeLastUsedNetwork(): bool
    {
        $v = $this->lastUsedNetwork;
        $this->lastUsedNetwork = false;
        return $v;
    }

    private function cellKey(float $lat, float $lon, float $deg): string
    {
        $rlat = $deg * \floor($lat / $deg);
        $rlon = $deg * \floor($lon / $deg);
        return \sprintf('%.4f,%.4f', $rlat, $rlon);
    }
}
