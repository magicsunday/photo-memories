<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use MagicSunday\Memories\Entity\Media;

/**
 * Abstraction used by the member quality ranking stage to fetch media entities.
 */
interface MemberMediaLookupInterface
{
    /**
     * @param list<int> $ids
     *
     * @return list<Media>
     */
    public function findByIds(array $ids): array;
}
