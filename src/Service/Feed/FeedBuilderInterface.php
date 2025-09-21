<?php
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
     * @return list<MemoryFeedItem>
     */
    public function build(array $clusters): array;
}
