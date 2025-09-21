<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

interface ReverseGeocoderInterface
{
    public function reverse(float $lat, float $lon, string $acceptLanguage = 'de'): ?GeocodeResult;
}
