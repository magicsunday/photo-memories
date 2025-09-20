<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service;

/**
 * Interface for renaming strategies.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
interface LocationServiceInterface
{
    /**
     * Returns a place title (e.g. "Rome, Italy") for coordinates.
     *
     * @param float $lat
     * @param float $lon
     *
     * @return null|string
     */
    public function reverseGeocode(float $lat, float $lon): ?string;
}
