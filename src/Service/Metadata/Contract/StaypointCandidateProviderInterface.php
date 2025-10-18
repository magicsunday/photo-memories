<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Contract;

use MagicSunday\Memories\Entity\Media;

/**
 * Resolves staypoint candidate media items for heuristics based on a seed media.
 */
interface StaypointCandidateProviderInterface
{
    /**
     * @return list<Media>
     */
    public function findCandidates(Media $seed, int $maxSamples = 500): array;
}
