<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

/**
 * Adds geoCell and distance-from-home using GPS.
 */
final readonly class GeoFeatureEnricher implements SingleMetadataExtractorInterface
{
    public function __construct(
        private float $homeLat,
        private float $homeLon,
        private float $cellDegrees = 0.01
    ) {
    }

    public function supports(string $filepath, Media $media): bool
    {
        return $media->getGpsLat() !== null && $media->getGpsLon() !== null;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $lat = (float) $media->getGpsLat();
        $lon = (float) $media->getGpsLon();

        $cell = $this->cellKey($lat, $lon, $this->cellDegrees);
        $media->setGeoCell8($cell);

        $distM = MediaMath::haversineDistanceInMeters($this->homeLat, $this->homeLon, $lat, $lon);
        $media->setDistanceKmFromHome($distM / 1000.0);

        return $media;
    }

    private function cellKey(float $lat, float $lon, float $gd): string
    {
        $rlat = $gd * \floor($lat / $gd);
        $rlon = $gd * \floor($lon / $gd);
        return \sprintf('%.4f,%.4f', $rlat, $rlon);
    }
}
