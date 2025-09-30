<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Support;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Cluster;

use function array_unique;
use function array_values;
use function is_string;
use function sort;

use const SORT_NUMERIC;

/**
 * Maps persisted Cluster entity to in-memory ClusterDraft (no mutation).
 */
final class ClusterEntityToDraftMapper
{
    /** @var array<string,string> */
    private array $algorithmGroups = [];

    public function __construct(
        array $algorithmGroups = [],
        private string $defaultAlgorithmGroup = 'default'
    ) {
        foreach ($algorithmGroups as $algorithm => $group) {
            if (!is_string($group) || $group === '') {
                continue;
            }

            $this->algorithmGroups[$algorithm] = $group;
        }

        if ($this->defaultAlgorithmGroup === '') {
            $this->defaultAlgorithmGroup = 'default';
        }
    }

    /**
     * @param list<Cluster> $entities
     *
     * @return list<ClusterDraft>
     */
    public function mapMany(array $entities): array
    {
        $out = [];
        foreach ($entities as $e) {
            $algorithm = $e->getAlgorithm();
            $params    = $e->getParams() ?? [];
            if (!isset($params['group']) || !is_string($params['group']) || $params['group'] === '') {
                $params['group'] = $this->resolveGroup($algorithm);
            }
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
     *
     * @return list<int>
     */
    private function normalizeMembers(array $members): array
    {
        $members = array_values(array_unique($members, SORT_NUMERIC));
        sort($members, SORT_NUMERIC);

        return $members;
    }

    private function resolveGroup(string $algorithm): string
    {
        $group = $this->algorithmGroups[$algorithm] ?? null;

        if (is_string($group) && $group !== '') {
            return $group;
        }

        return $this->defaultAlgorithmGroup;
    }
}
