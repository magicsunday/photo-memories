<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Weather;

use MagicSunday\Memories\Entity\Media;

/**
 * Default no-op provider: returns null for all queries.
 */
final class NullWeatherHintProvider implements WeatherHintProviderInterface
{
    public function getHint(Media $media): ?array
    {
        return null;
    }
}
