<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'memory')]
class Memory
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $clusterId = null;

    #[ORM\Column(type: Types::FLOAT)]
    private float $score = 0.0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $previewHtml = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(string $title, float $score = 0.0)
    {
        $this->title = $title;
        $this->score = $score;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }

    public function getTitle(): string { return $this->title; }

    public function setSummary(?string $summary): void { $this->summary = $summary; }

    public function getSummary(): ?string { return $this->summary; }

    public function setClusterId(?int $clusterId): void { $this->clusterId = $clusterId; }

    public function getClusterId(): ?int { return $this->clusterId; }

    public function setScore(float $score): void { $this->score = $score; }

    public function getScore(): float { return $this->score; }

    public function setPreviewHtml(?string $html): void { $this->previewHtml = $html; }

    public function getPreviewHtml(): ?string { return $this->previewHtml; }

    public function setStartAt(?DateTimeImmutable $dt): void { $this->startAt = $dt; }

    public function setEndAt(?DateTimeImmutable $dt): void { $this->endAt = $dt; }
}
