<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding\Contract;

/**
 * Resolves the display name for a POI based on extracted name information.
 */
interface PoiNameExtractorInterface
{
    /**
     * Determines the preferred POI name from the collected name candidates.
     *
     * @param array{
     *     default: ?string,
     *     localized: array<string, string>,
     *     alternates: list<string>
     * } $names
     */
    public function extract(array $names): ?string;
}
