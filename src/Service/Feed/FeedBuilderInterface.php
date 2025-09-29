<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Feed\MemoryFeedItem;

/**
 * Builds a user-facing feed from consolidated clusters.
 */
interface FeedBuilderInterface
{
    /**
     * @param list<ClusterDraft> $clusters Consolidated, scored clusters (score in params['score'])
     *
     * @return list<MemoryFeedItem>
     */
    public function build(array $clusters): array;
}
