<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Contract;

/**
 * Reports progress during the post-processing stages of the cluster build.
 */
interface ClusterBuildProgressCallbackInterface
{
    public const STAGE_SCORING_MEDIA = 'scoring.media';
    public const STAGE_SCORING = 'scoring';
    public const STAGE_TITLES = 'titles';

    public function onStageStart(string $stage, int $total): void;

    public function onStageProgress(string $stage, int $processed, int $total, ?string $detail = null): void;

    public function onStageFinish(string $stage, int $total): void;
}
