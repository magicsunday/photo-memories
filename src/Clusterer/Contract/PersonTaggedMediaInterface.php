<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Contract;

/**
 * Describes media objects that expose stable person identifiers.
 */
interface PersonTaggedMediaInterface
{
    /**
     * Returns the identifiers of persons associated with the media item.
     *
     * @return list<int>
     */
    public function getPersonIds(): array;
}
