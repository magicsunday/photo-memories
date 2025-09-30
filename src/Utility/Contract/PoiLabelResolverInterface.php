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
 * Resolves human readable labels for normalised POIs.
 */
interface PoiLabelResolverInterface
{
    /**
     * @param array{
     *     name:?string,
     *     names:array{default:?string,localized:array<string,string>,alternates:list<string>},
     *     categoryKey:?string,
     *     categoryValue:?string,
     *     tags:array<string,string>
     * } $poi
     */
    public function preferredLabel(array $poi): ?string;
}
