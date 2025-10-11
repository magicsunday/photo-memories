<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection;

/**
 * Curates raw cluster members into a concise, story-driven subset.
 */
interface ClusterMemberSelectorInterface
{
    /**
     * Curates the provided member identifiers using the configured policy.
     *
     * @param string                      $algorithm identifies the originating strategy
     * @param list<int>                   $memberIds ordered list of raw members
     * @param MemberSelectionContext|null $context   optional context with draft and media metadata
     */
    public function select(string $algorithm, array $memberIds, ?MemberSelectionContext $context = null): MemberSelectionResult;
}
