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
use MagicSunday\Memories\Entity\Enum\ClusterMemberRole;

/**
 * Persisted link between a cluster and one of its member media items.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'memories_cluster_member',
    indexes: [
        new ORM\Index(name: 'idx_memories_cluster_member_cluster_order', columns: ['cluster_id', 'ordering']),
        new ORM\Index(name: 'idx_memories_cluster_member_media', columns: ['media_id']),
    ],
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_memories_cluster_member', columns: ['cluster_id', 'media_id'])
    ]
)]
#[ORM\HasLifecycleCallbacks]
class ClusterMember
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Cluster::class, inversedBy: 'clusterMembers')]
    #[ORM\JoinColumn(name: 'cluster_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Cluster $cluster;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(name: 'media_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Media $media;

    #[ORM\Column(name: 'role', type: Types::STRING, enumType: ClusterMemberRole::class)]
    private ClusterMemberRole $role;

    #[ORM\Column(name: 'local_score', type: Types::FLOAT, nullable: true)]
    private ?float $localScore = null;

    #[ORM\Column(name: 'ordering', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $ordering;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Cluster $cluster,
        Media $media,
        ClusterMemberRole $role,
        int $ordering,
        ?float $localScore = null,
    ) {
        $this->cluster    = $cluster;
        $this->media      = $media;
        $this->role       = $role;
        $this->ordering   = $ordering;
        $this->localScore = $localScore;
        $this->createdAt  = new DateTimeImmutable();
        $this->updatedAt  = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCluster(): Cluster
    {
        return $this->cluster;
    }

    public function setCluster(?Cluster $cluster): void
    {
        if ($cluster === null || $this->cluster === $cluster) {
            return;
        }

        $this->cluster = $cluster;
        $this->touch();
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function setMedia(Media $media): void
    {
        $this->media = $media;
        $this->touch();
    }

    public function getRole(): ClusterMemberRole
    {
        return $this->role;
    }

    public function setRole(ClusterMemberRole $role): void
    {
        $this->role = $role;
        $this->touch();
    }

    public function getLocalScore(): ?float
    {
        return $this->localScore;
    }

    public function setLocalScore(?float $localScore): void
    {
        $this->localScore = $localScore;
        $this->touch();
    }

    public function getOrdering(): int
    {
        return $this->ordering;
    }

    public function setOrdering(int $ordering): void
    {
        $this->ordering = $ordering;
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
