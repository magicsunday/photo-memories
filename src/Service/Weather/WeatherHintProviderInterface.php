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
