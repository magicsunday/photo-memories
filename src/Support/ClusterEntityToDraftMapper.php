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

use function array_map;
use function array_unique;
use function array_values;
use function is_string;
use function sort;

use const SORT_NUMERIC;

/**
 * Maps persisted Cluster entities to immutable draft objects that are used by
 * the clustering strategies.
 */
final class ClusterEntityToDraftMapper
{
    /**
     * Lookup table that maps the configured clustering algorithm to a logical
     * group name used by the UI.
     *
     * @var array<string, string>
     */
    private array $algorithmGroups = [];

    /**
     * Provides the fallback group name when no explicit mapping exists.
     */
    private string $defaultAlgorithmGroup;

    /**
     * @param array<string, string> $algorithmGroups configuration that maps the algorithm identifier
     *                                               to the human-readable group label
     * @param string                $defaultGroup    optional default group name used when neither the
     *                                               entity nor configuration defines a mapping
     */
    public function __construct(
        array $algorithmGroups = [],
        string $defaultGroup = 'default',
    ) {
        $this->defaultAlgorithmGroup = $defaultGroup;

        // Build a normalized lookup table that only contains non-empty string values.
        foreach ($algorithmGroups as $algorithm => $group) {
            if (!is_string($group) || $group === '') {
                continue;
            }

            $this->algorithmGroups[$algorithm] = $group;
        }
    }

    /**
     * Converts a list of persisted cluster entities into their in-memory draft
     * representation.
     *
     * @param list<Cluster> $entities the clusters retrieved from the persistence layer
     *
     * @return list<ClusterDraft> the mapped draft objects used by the strategies
     */
    public function mapMany(array $entities): array
    {
        return array_map(function (Cluster $entity): ClusterDraft {
            $algorithm = $entity->getAlgorithm();
            $params    = $entity->getParams() ?? [];

            // Ensure every cluster is assigned to a group even if the entity did not persist one.
            if (!isset($params['group']) || !is_string($params['group']) || $params['group'] === '') {
                $params['group'] = $this->resolveGroup($algorithm);
            }

            $members = $this->normalizeMembers($entity->getMembers());

            // Create a fresh draft object to avoid mutating the persisted entity state.
            $draft = new ClusterDraft(
                algorithm: $algorithm,
                params: $params,
                centroid: $entity->getCentroid(),
                members: $members,
                storyline: is_string($params['storyline'] ?? null) ? $params['storyline'] : null,
            );

            $draft->setStartAt($entity->getStartAt());
            $draft->setEndAt($entity->getEndAt());
            $draft->setMembersCount($entity->getMembersCount());
            $draft->setPhotoCount($entity->getPhotoCount());
            $draft->setVideoCount($entity->getVideoCount());
            $draft->setCoverMediaId($entity->getCover()?->getId());
            $draft->setLocation($entity->getLocation());
            $draft->setAlgorithmVersion($entity->getAlgorithmVersion());
            $draft->setConfigHash($entity->getConfigHash());
            $draft->setCentroidLat($entity->getCentroidLat());
            $draft->setCentroidLon($entity->getCentroidLon());
            $draft->setCentroidCell7($entity->getCentroidCell7());

            return $draft;
        }, $entities);
    }

    /**
     * Normalizes the list of member media identifiers by removing duplicates
     * and sorting the values numerically.
     *
     * @param list<int> $members the raw member identifier list from the entity
     *
     * @return list<int> the normalized list of unique member identifiers
     */
    private function normalizeMembers(array $members): array
    {
        $members = array_values(array_unique($members, SORT_NUMERIC));
        sort($members, SORT_NUMERIC);

        return $members;
    }

    /**
     * Resolves the group name for the provided algorithm, falling back to the
     * configured default when no mapping exists.
     *
     * @param string $algorithm name of the algorithm stored on the entity
     *
     * @return string the resolved group name
     */
    private function resolveGroup(string $algorithm): string
    {
        $group = $this->algorithmGroups[$algorithm] ?? null;

        if (is_string($group) && $group !== '') {
            return $group;
        }

        return $this->defaultAlgorithmGroup;
    }
}
