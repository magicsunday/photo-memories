<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Support;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Cluster;

/**
 * Maps persisted Cluster entity to in-memory ClusterDraft (no mutation).
 */
final class ClusterEntityToDraftMapper
{
    /**
     * @param list<Cluster> $entities
     * @return list<ClusterDraft>
     */
    public function mapMany(array $entities): array
    {
        $out = [];
        foreach ($entities as $e) {
            $algorithm = $e->getAlgorithm();
            $params    = $e->getParams() ?? [];
            $centroid  = $e->getCentroid();
            $members   = $this->normalizeMembers($e->getMembers());

            $out[] = new ClusterDraft(
                algorithm: $algorithm,
                params: $params,
                centroid: $centroid,
                members: $members
            );
        }

        return $out;
    }

    /**
     * @param list<int> $members
     * @return list<int>
     */
    private function normalizeMembers(array $members): array
    {
        $members = \array_values(\array_unique($members, \SORT_NUMERIC));
        \sort($members, \SORT_NUMERIC);
        return $members;
    }
}
