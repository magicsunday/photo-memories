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

        foreach ($names['localized'] as $name) {
            if ($name !== '') {
                return $name;
            }
        }

        foreach ($names['alternates'] as $alternate) {
            if ($alternate !== '') {
                return $alternate;
            }
        }

        return null;
    }
}
