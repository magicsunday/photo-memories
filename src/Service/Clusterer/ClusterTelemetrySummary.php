<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use DateTimeImmutable;

/**
 * Snapshot describing a high-value cluster identified during a job run.
 */
final readonly class ClusterTelemetrySummary
{
    public function __construct(
        private string $algorithm,
        private string $storyline,
        private int $membersCount,
        private ?float $score,
        private ?DateTimeImmutable $startAt,
        private ?DateTimeImmutable $endAt,
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

    public function getMembersCount(): int
    {
        return $this->membersCount;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function getStartAt(): ?DateTimeImmutable
    {
        return $this->startAt;
    }

    public function getEndAt(): ?DateTimeImmutable
    {
        return $this->endAt;
    }
}
