<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility\Contract;

/**
 * Normalises raw POI payloads from geocoding providers.
 */
interface PoiNormalizerInterface
{
    /**
     * @param array<string, mixed> $poi
     *
     * @return array{
     *     name:string|null,
     *     names:array{default:string|null,localized:array<string,string>,alternates:list<string>},
     *     categoryKey:string|null,
     *     categoryValue:string|null,
     *     tags:array<string,string>
     * }|null
     */
    public function normalise(array $poi): ?array;
}
