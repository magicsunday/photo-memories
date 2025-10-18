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
use MagicSunday\Memories\Entity\Enum\SignificantPlaceKind;

/**
 * Persistent representation of a significant place detected from user activity.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'memories_significant_place',
    indexes: [
        new ORM\Index(name: 'idx_memories_significant_place_kind', columns: ['kind']),
        new ORM\Index(name: 'idx_memories_significant_place_geometry', columns: ['geometry'], flags: ['gin']),
    ]
)]
#[ORM\HasLifecycleCallbacks]
class SignificantPlace
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'kind', type: Types::STRING, enumType: SignificantPlaceKind::class)]
    private SignificantPlaceKind $kind;

    #[ORM\Column(name: 'label', type: Types::STRING, length: 191, nullable: true)]
    private ?string $label = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Location $location = null;

    /**
     * GeoJSON geometry describing the spatial footprint.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'geometry', type: Types::JSON)]
    private array $geometry;

    #[ORM\Column(name: 'centroid_lat', type: Types::FLOAT, nullable: true)]
    private ?float $centroidLat = null;

    #[ORM\Column(name: 'centroid_lon', type: Types::FLOAT, nullable: true)]
    private ?float $centroidLon = null;

    #[ORM\Column(name: 'radius_meters', type: Types::FLOAT, nullable: true)]
    private ?float $radiusMeters = null;

    #[ORM\Column(name: 'confidence', type: Types::FLOAT, nullable: true)]
    private ?float $confidence = null;

    #[ORM\Column(name: 'meta', type: Types::JSON, nullable: true)]
    private array $meta = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(SignificantPlaceKind $kind, array $geometry)
    {
        $this->kind      = $kind;
        $this->geometry  = $geometry;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): SignificantPlaceKind
    {
        return $this->kind;
    }

    public function setKind(SignificantPlaceKind $kind): void
    {
        $this->kind = $kind;
        $this->touch();
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label !== null && $label !== '' ? $label : null;
        $this->touch();
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function setLocation(?Location $location): void
    {
        $this->location = $location;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getGeometry(): array
    {
        return $this->geometry;
    }

    /**
     * @param array<string, mixed> $geometry
     */
    public function setGeometry(array $geometry): void
    {
        $this->geometry = $geometry;
        $this->touch();
    }

    public function getCentroidLat(): ?float
    {
        return $this->centroidLat;
    }

    public function setCentroidLat(?float $centroidLat): void
    {
        $this->centroidLat = $centroidLat;
        $this->touch();
    }

    public function getCentroidLon(): ?float
    {
        return $this->centroidLon;
    }

    public function setCentroidLon(?float $centroidLon): void
    {
        $this->centroidLon = $centroidLon;
        $this->touch();
    }

    public function getRadiusMeters(): ?float
    {
        return $this->radiusMeters;
    }

    public function setRadiusMeters(?float $radiusMeters): void
    {
        $this->radiusMeters = $radiusMeters;
        $this->touch();
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function setConfidence(?float $confidence): void
    {
        $this->confidence = $confidence;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    public function setMeta(?array $meta): void
    {
        $this->meta = $meta ?? [];
        $this->touch();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new DateTimeImmutable();
        $this->createdAt = $this->createdAt ?? $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
