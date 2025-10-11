<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;

/**
 * Provides contextual data required by the policy-driven selector.
 */
final class MemberSelectionContext
{
    /**
     * @param ClusterDraft            $draft         originating cluster draft
     * @param SelectionPolicy         $policy        resolved selection policy for the algorithm
     * @param array<int, Media>       $mediaMap      keyed map of media entities used during curation
     * @param array<int, float|null>  $qualityScores per-member quality scores supplied by the ranking stage
     */
    public function __construct(
        private readonly ClusterDraft $draft,
        private readonly SelectionPolicy $policy,
        private readonly array $mediaMap,
        private readonly array $qualityScores,
    ) {
    }

    public function getDraft(): ClusterDraft
    {
        return $this->draft;
    }

    public function getPolicy(): SelectionPolicy
    {
        return $this->policy;
    }

    /**
     * @return array<int, Media>
     */
    public function getMediaMap(): array
    {
        return $this->mediaMap;
    }

    /**
     * @return array<int, float|null>
     */
    public function getQualityScores(): array
    {
        return $this->qualityScores;
    }
}
