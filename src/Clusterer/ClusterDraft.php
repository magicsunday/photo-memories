<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Location;

/**
 * Minimal DTO used across strategies and persistence.
 */
final class ClusterDraft
{
    /**
     * @param array<string, scalar|array|null> $params
     * @param array{lat: float, lon: float}    $centroid
     * @param list<int>                        $members
     */
    public function __construct(
        private readonly string $algorithm,
        private array $params,
        private readonly array $centroid,
        private readonly array $members,
    ) {
        $this->membersCount = count($members);
        $this->centroidLat  = $centroid['lat'] ?? null;
        $this->centroidLon  = $centroid['lon'] ?? null;
    }

    private ?DateTimeImmutable $startAt = null;

    private ?DateTimeImmutable $endAt = null;

    private int $membersCount = 0;

    private ?int $photoCount = null;

    private ?int $videoCount = null;

    private ?int $coverMediaId = null;

    private ?Location $location = null;

    private ?string $algorithmVersion = null;

    private ?string $configHash = null;

    private ?float $centroidLat = null;

    private ?float $centroidLon = null;

    private ?string $centroidCell7 = null;

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * @return array<string, scalar|array|null>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param scalar|array|null $value
     */
    public function setParam(string $key, $value): void
    {
        $this->params[$key] = $value;
    }

    /**
     * @return array{lat: float, lon: float}
     */
    public function getCentroid(): array
    {
        return $this->centroid;
    }

    /**
     * @return list<int>
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    public function getStartAt(): ?DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(?DateTimeImmutable $startAt): void
    {
        $this->startAt = $startAt;
    }

    public function getEndAt(): ?DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(?DateTimeImmutable $endAt): void
    {
        $this->endAt = $endAt;
    }

    public function getMembersCount(): int
    {
        return $this->membersCount;
    }

    public function setMembersCount(int $count): void
    {
        $this->membersCount = $count;
    }

    public function getPhotoCount(): ?int
    {
        return $this->photoCount;
    }

    public function setPhotoCount(?int $count): void
    {
        $this->photoCount = $count;
    }

    public function getVideoCount(): ?int
    {
        return $this->videoCount;
    }

    public function setVideoCount(?int $count): void
    {
        $this->videoCount = $count;
    }

    public function getCoverMediaId(): ?int
    {
        return $this->coverMediaId;
    }

    public function setCoverMediaId(?int $mediaId): void
    {
        $this->coverMediaId = $mediaId;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function setLocation(?Location $location): void
    {
        $this->location = $location;
    }

    public function getAlgorithmVersion(): ?string
    {
        return $this->algorithmVersion;
    }

    public function setAlgorithmVersion(?string $version): void
    {
        $this->algorithmVersion = $version;
    }

    public function getConfigHash(): ?string
    {
        return $this->configHash;
    }

    public function setConfigHash(?string $hash): void
    {
        $this->configHash = $hash;
    }

    public function getCentroidLat(): ?float
    {
        return $this->centroidLat;
    }

    public function setCentroidLat(?float $lat): void
    {
        $this->centroidLat = $lat;
    }

    public function getCentroidLon(): ?float
    {
        return $this->centroidLon;
    }

    public function setCentroidLon(?float $lon): void
    {
        $this->centroidLon = $lon;
    }

    public function getCentroidCell7(): ?string
    {
        return $this->centroidCell7;
    }

    public function setCentroidCell7(?string $cell): void
    {
        $this->centroidCell7 = $cell;
    }
}
