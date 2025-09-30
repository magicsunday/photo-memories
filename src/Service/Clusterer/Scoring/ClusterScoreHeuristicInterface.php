<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;

/**
 * Contract for heuristics contributing to composite cluster scores.
 */
interface ClusterScoreHeuristicInterface
{
    /**
     * @param list<ClusterDraft> $clusters
     * @param array<int, Media>  $mediaMap
     */
    public function prepare(array $clusters, array $mediaMap): void;

    public function supports(ClusterDraft $cluster): bool;

    /**
     * @param array<int, Media> $mediaMap
     */
    public function enrich(ClusterDraft $cluster, array $mediaMap): void;

    public function score(ClusterDraft $cluster): float;

    public function weightKey(): string;
}
