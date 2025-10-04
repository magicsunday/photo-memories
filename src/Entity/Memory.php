<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Curated memory entry aggregating media into a narrative story.
 */
#[ORM\Entity]
#[ORM\Table(name: 'memory')]
class Memory
{
    /**
     * Surrogate identifier of the memory entry.
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private int $id;

    /**
     * Title presented to the user.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title;

    /**
     * Optional summary describing the memory.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /**
     * Identifier of the underlying cluster that inspired the memory.
     */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $clusterId = null;

    /**
     * Relevance score used to rank memory suggestions.
     */
    #[ORM\Column(type: Types::FLOAT)]
    private float $score = 0.0;

    /**
     * Generated HTML snippet used for previews.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $previewHtml = null;

    /**
     * Start timestamp of the memory timeframe.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startAt = null;

    /**
     * End timestamp of the memory timeframe.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endAt = null;

    /**
     * Timestamp indicating when the memory entity was created.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * @param string $title Display title.
     * @param float  $score Initial relevance score.
     */
    public function __construct(string $title, float $score = 0.0)
    {
        $this->title = $title;
        $this->score = $score;

        // Capture the creation timestamp once to maintain chronology.
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * Returns the memory identifier.
     *
     * @return int Memory identifier.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Returns the memory title.
     *
     * @return string Memory title.
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Updates the summary text.
     *
     * @param string|null $summary Summary text.
     */
    public function setSummary(?string $summary): void
    {
        $this->summary = $summary;
    }

    /**
     * Returns the summary text.
     *
     * @return string|null Summary text or null when not set.
     */
    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * Associates the memory with a cluster.
     *
     * @param int|null $clusterId Identifier of the cluster.
     */
    public function setClusterId(?int $clusterId): void
    {
        $this->clusterId = $clusterId;
    }

    /**
     * Returns the associated cluster identifier.
     *
     * @return int|null Identifier of the cluster or null if none linked.
     */
    public function getClusterId(): ?int
    {
        return $this->clusterId;
    }

    /**
     * Updates the relevance score.
     *
     * @param float $score Relevance score value.
     */
    public function setScore(float $score): void
    {
        $this->score = $score;
    }

    /**
     * Returns the relevance score.
     *
     * @return float Relevance score value.
     */
    public function getScore(): float
    {
        return $this->score;
    }

    /**
     * Updates the preview HTML snippet.
     *
     * @param string|null $html Preview markup.
     */
    public function setPreviewHtml(?string $html): void
    {
        $this->previewHtml = $html;
    }

    /**
     * Returns the preview HTML snippet.
     *
     * @return string|null Preview markup or null when not generated.
     */
    public function getPreviewHtml(): ?string
    {
        return $this->previewHtml;
    }

    /**
     * Sets the memory start timestamp.
     *
     * @param DateTimeImmutable|null $dt Start timestamp.
     */
    public function setStartAt(?DateTimeImmutable $dt): void
    {
        $this->startAt = $dt;
    }

    /**
     * Sets the memory end timestamp.
     *
     * @param DateTimeImmutable|null $dt End timestamp.
     */
    public function setEndAt(?DateTimeImmutable $dt): void
    {
        $this->endAt = $dt;
    }

    /**
     * Returns the memory start timestamp.
     *
     * @return DateTimeImmutable|null Start timestamp.
     */
    public function getStartAt(): ?DateTimeImmutable
    {
        return $this->startAt;
    }

    /**
     * Returns the memory end timestamp.
     *
     * @return DateTimeImmutable|null End timestamp.
     */
    public function getEndAt(): ?DateTimeImmutable
    {
        return $this->endAt;
    }

    /**
     * Returns the creation timestamp of the memory entity.
     *
     * @return DateTimeImmutable Creation timestamp.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
