<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

/**
 * Provides a summarised view on a consolidated cluster.
 */
final readonly class ClusterSummary
{
    public function __construct(
        private string $algorithm,
        private string $storyline,
        private int $rawMemberCount,
        private int $curatedMemberCount,
        private ?string $policyKey,
        private ?float $score,
        private ?ClusterSummaryTimeRange $timeRange,
    ) {
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    public function getStoryline(): string
    {
        return $this->storyline;
    }

    public function getRawMemberCount(): int
    {
        return $this->rawMemberCount;
    }

    public function getCuratedMemberCount(): int
    {
        return $this->curatedMemberCount;
    }

    public function getMemberCount(): int
    {
        return $this->curatedMemberCount;
    }

    public function getPolicyKey(): ?string
    {
        return $this->policyKey;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function getTimeRange(): ?ClusterSummaryTimeRange
    {
        return $this->timeRange;
    }
}
