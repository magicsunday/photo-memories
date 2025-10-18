<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\GeoHash;
use MagicSunday\Memories\Utility\MediaMath;
use MagicSunday\Memories\Utility\S2CellId;

use function assert;
use function floor;
use function hash;
use function sprintf;

/**
 * Adds geoCell and distance-from-home using GPS.
 */
final readonly class GeoFeatureEnricher implements SingleMetadataExtractorInterface
{
    public function __construct(
        private float $homeLat,
        private float $homeLon,
        private float $homeRadiusKm,
        private string $homeVersionHash,
        private float $cellDegrees = 0.01,
        private int $s2Level = 12,
    ) {
    }

    public function supports(string $filepath, Media $media): bool
    {
        return $media->getGpsLat() !== null && $media->getGpsLon() !== null;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $desiredHash = $this->computeHomeConfigHash();
        $currentHash = $media->getHomeConfigHash();

        $shouldUpdateHomeMetrics = $desiredHash !== $currentHash
            || $media->getGeoCell8() === null
            || $media->getDistanceKmFromHome() === null;

        $lat = $media->getGpsLat();
        $lon = $media->getGpsLon();
        assert($lat !== null && $lon !== null);

        if ($shouldUpdateHomeMetrics) {
            $cell = $this->cellKey($lat, $lon, $this->cellDegrees);
            $media->setGeoCell8($cell);

            $distM = MediaMath::haversineDistanceInMeters($this->homeLat, $this->homeLon, $lat, $lon);
            $media->setDistanceKmFromHome($distM / 1000.0);

            $media->setHomeConfigHash($desiredHash);
        }

        $media->setGeohash7(GeoHash::encode($lat, $lon, 7));
        $media->setGeohash5(GeoHash::encode($lat, $lon, 5));
        $media->setS2CellId(S2CellId::tokenFromDegrees($lat, $lon, $this->s2Level));

        $media->setNeedsGeocode($media->getLocation() === null);

        return $media;
    }

    private function cellKey(float $lat, float $lon, float $gd): string
    {
        $rlat = $gd * floor($lat / $gd);
        $rlon = $gd * floor($lon / $gd);

        return sprintf('%.4f,%.4f', $rlat, $rlon);
    }

    private function computeHomeConfigHash(): string
    {
        return hash(
            'sha256',
            sprintf('%.8f|%.8f|%.8f|%s', $this->homeLat, $this->homeLon, $this->homeRadiusKm, $this->homeVersionHash)
        );
    }
}
