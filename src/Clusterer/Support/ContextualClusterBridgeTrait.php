<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Context;

/**
 * Provides a default implementation for the Context-aware draft() method.
 */
trait ContextualClusterBridgeTrait
{
    /**
     * @param list<\MagicSunday\Memories\Entity\Media> $scope
     *
     * @return list<ClusterDraft>
     */
    public function draft(array $scope, Context $ctx): array
    {
        /** @var list<ClusterDraft> $drafts */
        $drafts = $this->cluster($scope);

        foreach ($drafts as $draft) {
            $ctx->applyToDraft($draft);
        }

        return $drafts;
    }
}
