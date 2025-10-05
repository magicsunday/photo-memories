<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\Contract\PoiNameExtractorInterface;

use function array_find;

/**
 * Class PoiNameExtractor
 */
final class PoiNameExtractor implements PoiNameExtractorInterface
{
    public function extract(array $names): ?string
    {
        $default = $names['default'];
        if ($default !== null) {
            return $default;
        }

        $localized = array_find(
            $names['localized'],
            static fn ($name): bool => $name !== ''
        );
        if ($localized !== null) {
            return $localized;
        }

        $alternate = array_find(
            $names['alternates'],
            static fn ($alternate): bool => $alternate !== ''
        );
        if ($alternate !== null) {
            return $alternate;
        }

        return null;
    }
}
