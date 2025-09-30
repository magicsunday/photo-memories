<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Feed;

/**
 * Simple DTO representing a feed card.
 *
 * The params array contains additional metadata such as the algorithm group
 * (key 'group') added by the cluster scorer/mapper pipeline.
 */
final readonly class MemoryFeedItem
{
    /**
     * @param list<int>                        $memberIds
     * @param array<string, scalar|array|null> $params
     */
    public function __construct(
        private string $algorithm,
        private string $title,
        private string $subtitle,
        private ?int $coverMediaId,
        private array $memberIds,
        private float $score,
        private array $params,
    ) {
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSubtitle(): string
    {
        return $this->subtitle;
    }

    public function getCoverMediaId(): ?int
    {
        return $this->coverMediaId;
    }

    /** @return list<int> */
    public function getMemberIds(): array
    {
        return $this->memberIds;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    /** @return array<string, scalar|array|null> */
    public function getParams(): array
    {
        return $this->params;
    }
}
