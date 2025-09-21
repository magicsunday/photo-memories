<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use MagicSunday\Memories\Entity\Media;

/**
 * Picks a single best cover Media for a cluster.
 */
interface CoverPickerInterface
{
    /**
     * @param list<Media>              $members
     * @param array<string,scalar|array|null> $clusterParams
     */
    public function pickCover(array $members, array $clusterParams): ?Media;
}
