<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Feed;

/**
 * Simple DTO representing a feed card.
 */
final class MemoryFeedItem
{
    /**
     * @param list<int> $memberIds
     * @param array<string, scalar|array|null> $params
     */
    public function __construct(
        private string $algorithm,
        private string $title,
        private string $subtitle,
        private ?int $coverMediaId,
        private array $memberIds,
        private float $score,
        private array $params
    ) {
    }

    public function getAlgorithm(): string { return $this->algorithm; }
    public function getTitle(): string { return $this->title; }
    public function getSubtitle(): string { return $this->subtitle; }
    public function getCoverMediaId(): ?int { return $this->coverMediaId; }

    /** @return list<int> */
    public function getMemberIds(): array { return $this->memberIds; }

    public function getScore(): float { return $this->score; }

    /** @return array<string, scalar|array|null> */
    public function getParams(): array { return $this->params; }
}
