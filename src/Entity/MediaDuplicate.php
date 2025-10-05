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
use InvalidArgumentException;

/**
 * Stores perceptual near-duplicate relationships between media assets.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'media_duplicate',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_media_duplicate_pair', columns: ['media_left_id', 'media_right_id'])
    ],
    indexes: [
        new ORM\Index(name: 'idx_media_duplicate_left', columns: ['media_left_id']),
        new ORM\Index(name: 'idx_media_duplicate_right', columns: ['media_right_id'])
    ]
)]
final class MediaDuplicate
{
    /**
     * Internal primary key managed by the database sequence.
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private int $id;

    /**
     * Canonical left-side media reference of the duplicate pair.
     */
    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(name: 'media_left_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Media $leftMedia;

    /**
     * Canonical right-side media reference of the duplicate pair.
     */
    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(name: 'media_right_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Media $rightMedia;

    /**
     * Hamming distance between the perceptual hashes of both assets.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $distance;

    /**
     * Timestamp when the duplicate pair was first detected.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * Timestamp when the distance value was last updated.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    /**
     * @throws InvalidArgumentException When the distance is negative or the media references are identical.
     */
    public function __construct(Media $leftMedia, Media $rightMedia, int $distance)
    {
        if ($distance < 0) {
            throw new InvalidArgumentException('Distance must be zero or greater.');
        }

        if ($leftMedia === $rightMedia) {
            throw new InvalidArgumentException('Duplicate pair requires distinct media instances.');
        }

        $this->leftMedia  = $leftMedia;
        $this->rightMedia = $rightMedia;
        $this->distance   = $distance;
        $this->createdAt  = new DateTimeImmutable();
    }

    /**
     * Returns the unique identifier assigned by Doctrine.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Returns the canonical left media entity.
     */
    public function getLeftMedia(): Media
    {
        return $this->leftMedia;
    }

    /**
     * Returns the canonical right media entity.
     */
    public function getRightMedia(): Media
    {
        return $this->rightMedia;
    }

    /**
     * Returns the recorded Hamming distance.
     */
    public function getDistance(): int
    {
        return $this->distance;
    }

    /**
     * Updates the stored Hamming distance.
     *
     * @throws InvalidArgumentException When the provided distance is negative.
     */
    public function setDistance(int $distance): void
    {
        if ($distance < 0) {
            throw new InvalidArgumentException('Distance must be zero or greater.');
        }

        if ($this->distance === $distance) {
            return;
        }

        $this->distance  = $distance;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Returns the timestamp when the duplicate pair was first recorded.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the timestamp of the last update, if available.
     */
    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
