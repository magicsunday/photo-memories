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
     *
     * @var string
     */
    private string $defaultAlgorithmGroup;

    /**
     * @param array<string, string> $algorithmGroups Configuration that maps the algorithm identifier
     *                                               to the human-readable group label.
     * @param string                $defaultGroup    Optional default group name used when neither the
     *                                               entity nor configuration defines a mapping.
     */
    public function __construct(
        array $algorithmGroups = [],
        string $defaultGroup = 'default'
    ) {
        $this->defaultAlgorithmGroup = $defaultGroup;

        // Build a normalized lookup table that only contains non-empty string values.
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
     * Converts a list of persisted cluster entities into their in-memory draft
     * representation.
     *
     * @param list<Cluster> $entities The clusters retrieved from the persistence layer.
     *
     * @return list<ClusterDraft> The mapped draft objects used by the strategies.
     */
    public function mapMany(array $entities): array
    {
        $out = [];
        foreach ($entities as $e) {
            $algorithm = $e->getAlgorithm();
            $params    = $e->getParams() ?? [];

            // Ensure every cluster is assigned to a group even if the entity did not persist one.
            if (!isset($params['group']) || !is_string($params['group']) || $params['group'] === '') {
                $params['group'] = $this->resolveGroup($algorithm);
            }
            $centroid  = $e->getCentroid();
            $members   = $this->normalizeMembers($e->getMembers());

            // Create a fresh draft object to avoid mutating the persisted entity state.
            $draft = new ClusterDraft(
                algorithm: $algorithm,
                params: $params,
                centroid: $centroid,
                members: $members
            );

            $draft->setStartAt($e->getStartAt());
            $draft->setEndAt($e->getEndAt());
            $draft->setMembersCount($e->getMembersCount());
            $draft->setPhotoCount($e->getPhotoCount());
            $draft->setVideoCount($e->getVideoCount());
            $draft->setCoverMediaId($e->getCover()?->getId());
            $draft->setLocation($e->getLocation());
            $draft->setAlgorithmVersion($e->getAlgorithmVersion());
            $draft->setConfigHash($e->getConfigHash());
            $draft->setCentroidLat($e->getCentroidLat());
            $draft->setCentroidLon($e->getCentroidLon());
            $draft->setCentroidCell7($e->getCentroidCell7());

            $out[] = $draft;
        }

        return $out;
    }

    /**
     * Normalizes the list of member media identifiers by removing duplicates
     * and sorting the values numerically.
     *
     * @param list<int> $members The raw member identifier list from the entity.
     *
     * @return list<int> The normalized list of unique member identifiers.
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
     * @param string $algorithm Name of the algorithm stored on the entity.
     *
     * @return string The resolved group name.
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
