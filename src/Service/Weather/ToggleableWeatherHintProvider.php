<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Weather;

use MagicSunday\Memories\Entity\Media;

/**
 * Delegates weather lookups to a primary provider that can be disabled via configuration.
 */
final class ToggleableWeatherHintProvider implements WeatherHintProviderInterface
{
    public function __construct(
        private readonly WeatherHintProviderInterface $primary,
        private readonly WeatherHintProviderInterface $fallback,
        private readonly bool $enabled
    ) {
    }

    public function getHint(Media $media): ?array
    {
        if (!$this->enabled) {
            return $this->fallback->getHint($media);
        }

        $hint = $this->primary->getHint($media);
        if ($hint !== null) {
            return $hint;
        }

        return $this->fallback->getHint($media);
    }
}

