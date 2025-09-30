<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility\Contract;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;

/**
 * Resolves human readable labels and keys for locations.
 */
interface LocationLabelResolverInterface
{
    public function localityKey(?Location $location): ?string;

    public function displayLabel(?Location $location): ?string;

    public function localityKeyForMedia(Media $media): ?string;

    public function labelForMedia(Media $media): ?string;

    /**
     * @param list<Media> $members
     */
    public function majorityLabel(array $members): ?string;

    /**
     * @param list<Media> $members
     *
     * @return array{country?:string,region?:string,city?:string}
     */
    public function majorityLocationComponents(array $members): array;

    public function sameLocality(Media $a, Media $b): bool;
}
