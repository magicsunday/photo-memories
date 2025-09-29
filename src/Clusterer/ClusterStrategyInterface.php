<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Entity\Media;

/**
 * Light-weight contract every strategy already follows in deinem Projekt.
 */
interface ClusterStrategyInterface
{
    /**
     * @param list<Media> $items Sorted or unsorted
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array;

    public function name(): string;
}
