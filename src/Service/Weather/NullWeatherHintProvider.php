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
 * Default no-op provider: returns null for all queries.
 */
final class NullWeatherHintProvider implements WeatherHintProviderInterface
{
    public function getHint(Media $media): ?array
    {
        return null;
    }
}
