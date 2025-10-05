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
use MagicSunday\Memories\Entity\Media;

use function count;
use function explode;
use function floor;
use function implode;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * Links Media to Locations; uses a pre-warmed cell index + in-run cache.
 */
final class MediaLocationLinker implements MediaLocationLinkerInterface
{
    /** @var array<string,Location> in-run cache: cell -> Location (managed) */
    private array $cellCache = [];

    private int $lastNetworkCalls = 0;

    public function __construct(
        private readonly ReverseGeocoderInterface $geocoder,
        private readonly LocationResolver $resolver,
        private readonly LocationCellIndex $cellIndex,
        private readonly float $cellDeg = 0.01,
    ) {
    }

    public function link(Media $media, string $acceptLanguage = 'de', bool $forceRefreshPois = false): ?Location
    {
        $this->lastNetworkCalls = 0;

        $lat = $media->getGpsLat();
        $lon = $media->getGpsLon();
        if ($lat === null || $lon === null) {
            return null;
        }

        $cell = $this->cellKey($lat, $lon, $this->cellDeg);

        // 1) in-run cache first
        if (isset($this->cellCache[$cell])) {
            $loc = $this->cellCache[$cell];
            $this->ensurePois($loc, $forceRefreshPois);
            $media->setLocation($loc);
            $media->setNeedsGeocode(false);

            return $loc;
        }

        // 2) pre-warmed DB index
        $fromIndex = $this->cellIndex->findByCell($cell);
        if ($fromIndex instanceof Location) {
            $this->cellCache[$cell] = $fromIndex;
            $this->ensurePois($fromIndex, $forceRefreshPois);
            $media->setLocation($fromIndex);
            $media->setNeedsGeocode(false);

            return $fromIndex;
        }

        // 3) network path once per cell
        $acceptLanguage = $this->normalizeAcceptLanguage($acceptLanguage);

        $result = $this->geocoder->reverse($lat, $lon, $acceptLanguage);
        if (!$result instanceof GeocodeResult) {
            return null;
        }

        $networkCalls = 1; // reverse geocoding uses one request

        $loc = $this->resolver->findOrCreate($result);
        if ($this->resolver->consumeLastUsedNetwork()) {
            ++$networkCalls;
        }

        $this->cellCache[$cell] = $loc;
        $this->cellIndex->remember($cell, $loc);
        $this->lastNetworkCalls = $networkCalls;

        $media->setLocation($loc);
        $media->setNeedsGeocode(false);

        return $loc;
    }

    public function consumeLastNetworkCalls(): int
    {
        $v                      = $this->lastNetworkCalls;
        $this->lastNetworkCalls = 0;

        return $v;
    }

    private function cellKey(float $lat, float $lon, float $deg): string
    {
        $rlat = $deg * floor($lat / $deg);
        $rlon = $deg * floor($lon / $deg);

        return sprintf('%.4f,%.4f', $rlat, $rlon);
    }

    private function ensurePois(Location $location, bool $forceRefresh): void
    {
        $this->resolver->ensurePois($location, $forceRefresh);

        if ($this->resolver->consumeLastUsedNetwork()) {
            ++$this->lastNetworkCalls;
        }
    }

    private function normalizeAcceptLanguage(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'de';
        }

        if (str_contains($trimmed, ',')) {
            return $trimmed;
        }

        $normalized = str_replace('_', '-', $trimmed);
        $normalized = explode('.', $normalized, 2)[0];
        $parts      = explode('-', $normalized);
        $count      = count($parts);

        if ($count === 0) {
            return 'de';
        }

        $parts[0] = strtolower($parts[0]);

        for ($i = 1; $i < $count; ++$i) {
            $parts[$i] = strtoupper($parts[$i]);
        }

        $header = implode('-', $parts);

        if ($header === '') {
            return 'de';
        }

        return $header;
    }
}
