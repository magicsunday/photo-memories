<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidationStageInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidatorInterface;

/**
 * Executes a configurable list of consolidation stages sequentially.
 */
final readonly class PipelineClusterConsolidator implements ClusterConsolidatorInterface
{
    /**
     * @param iterable<ClusterConsolidationStageInterface> $stages
     */
    public function __construct(private iterable $stages)
    {
    }

    /**
     * @param list<ClusterDraft>                                     $drafts
     * @param callable(int $done, int $max, string $stage):void|null $progress
     *
     * @return list<ClusterDraft>
     */
    public function consolidate(array $drafts, ?callable $progress = null): array
    {
        foreach ($this->stages as $stage) {
            $stageProgress = null;
            if ($progress !== null) {
                $label         = $stage->getLabel();
                $stageProgress = static function (int $done, int $max) use ($progress, $label): void {
                    $progress($done, $max, $label);
                };
            }

            $drafts = $stage->process($drafts, $stageProgress);
        }

        return $drafts;
    }
}
