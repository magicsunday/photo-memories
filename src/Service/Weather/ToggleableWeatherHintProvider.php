<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Weather;

use MagicSunday\Memories\Entity\Media;

/**
 * Delegates weather lookups to a primary provider that can be disabled via configuration.
 */
final readonly class ToggleableWeatherHintProvider implements WeatherHintProviderInterface
{
    public function __construct(
        private WeatherHintProviderInterface $primary,
        private WeatherHintProviderInterface $fallback,
        private bool $enabled,
    ) {
    }

    public function getHint(Media $media): ?array
    {
        if ($this->enabled) {
            $hint = $this->primary->getHint($media);

            if ($hint !== null) {
                return $hint;
            }
        }

        return $this->fallback->getHint($media);
    }
}
