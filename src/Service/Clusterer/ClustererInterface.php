<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

interface ClustererInterface
{
    /**
     * Cluster the given media items into groups.
     *
     * @param array $items List of media items
     * @return array Clustered groups of items
     */
    public function cluster(array $items): array;
}
