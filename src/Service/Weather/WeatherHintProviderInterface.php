<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Weather;

use MagicSunday\Memories\Entity\Media;

/**
 * Provides per-media weather hints (e.g., parsed from EXIF or sidecar files).
 * Return null if unknown/no data for the given media.
 */
interface WeatherHintProviderInterface
{
    /**
     * @return array{rain_prob: float, precip_mm?: float}|null
     */
    public function getHint(Media $media): ?array;
}
