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

use function count;

/**
 * Immutable data transfer object that captures the calculated cluster state
 * shared by clustering strategies and persistence adapters.
 */
final class ClusterDraft
{
    /**
     * Name of the algorithm that produced the cluster.
     */
    private readonly string $algorithm;

    /**
     * Raw configuration parameters provided by the clustering strategy.
     *
     * @var array<string, int|float|string|bool|array|null>
     */
    private array $params;

    /**
     * Geographic centroid coordinates (latitude/longitude pair).
     *
     * @var array{lat: float, lon: float}
     */
    private readonly array $centroid;

    /**
     * Ordered identifiers of media entities that belong to the cluster.
     *
     * @var list<int>
     */
    private readonly array $members;

    /**
     * @param array<string, int|float|string|bool|array|null> $params
     * @param array{lat: float, lon: float}                   $centroid
     * @param list<int>                                       $members
     */
    public function __construct(
        string $algorithm,
        array $params,
        array $centroid,
        array $members,
    ) {
        $this->algorithm = $algorithm;
        $this->params    = $params;
        $this->centroid  = $centroid;
        $this->members   = $members;

        // Calculate basic cluster statistics that are derived from the constructor arguments.
        $this->membersCount = count($members);
        $this->centroidLat  = $centroid['lat'] ?? null;
        $this->centroidLon  = $centroid['lon'] ?? null;
    }

    /**
     * Marks the timestamp of the first media item that belongs to the cluster.
     */
    private ?DateTimeImmutable $startAt = null;

    /**
     * Marks the timestamp of the last media item that belongs to the cluster.
     */
    private ?DateTimeImmutable $endAt = null;

    /**
     * Cached count of members for quick read access without recalculating the array size.
     */
    private int $membersCount = 0;

    /**
     * Number of photos that belong to the cluster.
     */
    private ?int $photoCount = null;

    /**
     * Number of videos that belong to the cluster.
     */
    private ?int $videoCount = null;

    /**
     * Identifier of the media entity that represents the cover image.
     */
    private ?int $coverMediaId = null;

    /**
     * Optional location associated with the cluster.
     */
    private ?Location $location = null;

    /**
     * Version string of the algorithm that produced the cluster.
     */
    private ?string $algorithmVersion = null;

    /**
     * Hash of the configuration that was used during clustering.
     */
    private ?string $configHash = null;

    /**
     * Latitude of the cluster centroid stored for quick access.
     */
    private ?float $centroidLat = null;

    /**
     * Longitude of the cluster centroid stored for quick access.
     */
    private ?float $centroidLon = null;

    /**
     * S2 cell identifier with level 7 precision representing the centroid.
     */
    private ?string $centroidCell7 = null;

    /**
     * Returns the name of the algorithm that generated the cluster.
     *
     * @return string
     */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * @return array<string, int|float|string|bool|array|null>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Overrides a single configuration parameter on the draft.
     *
     * @param string                           $key   name of the parameter to override
     * @param int|float|string|bool|array|null $value updated parameter value
     */
    public function setParam(string $key, int|float|string|bool|array|null $value): void
    {
        $this->params[$key] = $value;
    }

    /**
     * Provides the centroid coordinates as latitude/longitude array.
     *
     * @return array{lat: float, lon: float}
     */
    public function getCentroid(): array
    {
        return $this->centroid;
    }

    /**
     * Returns the ordered identifiers of media entities that belong to the cluster.
     *
     * @return list<int>
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    /**
     * Returns the timestamp of the first media item.
     *
     * @return DateTimeImmutable|null
     */
    public function getStartAt(): ?DateTimeImmutable
    {
        return $this->startAt;
    }

    /**
     * Sets the timestamp of the first media item.
     *
     * @param DateTimeImmutable|null $startAt timestamp of the first item in the cluster
     */
    public function setStartAt(?DateTimeImmutable $startAt): void
    {
        $this->startAt = $startAt;
    }

    /**
     * Returns the timestamp of the last media item.
     *
     * @return DateTimeImmutable|null
     */
    public function getEndAt(): ?DateTimeImmutable
    {
        return $this->endAt;
    }

    /**
     * Sets the timestamp of the last media item.
     *
     * @param DateTimeImmutable|null $endAt timestamp of the last item in the cluster
     */
    public function setEndAt(?DateTimeImmutable $endAt): void
    {
        $this->endAt = $endAt;
    }

    /**
     * Returns the cached number of members.
     *
     * @return int
     */
    public function getMembersCount(): int
    {
        return $this->membersCount;
    }

    /**
     * Updates the cached number of members.
     *
     * @param int $count number of members associated with the cluster
     */
    public function setMembersCount(int $count): void
    {
        $this->membersCount = $count;
    }

    /**
     * Returns how many photos belong to the cluster.
     *
     * @return int|null
     */
    public function getPhotoCount(): ?int
    {
        return $this->photoCount;
    }

    /**
     * Sets how many photos belong to the cluster.
     *
     * @param int|null $count number of photos
     */
    public function setPhotoCount(?int $count): void
    {
        $this->photoCount = $count;
    }

    /**
     * Returns how many videos belong to the cluster.
     *
     * @return int|null
     */
    public function getVideoCount(): ?int
    {
        return $this->videoCount;
    }

    /**
     * Sets how many videos belong to the cluster.
     *
     * @param int|null $count number of videos
     */
    public function setVideoCount(?int $count): void
    {
        $this->videoCount = $count;
    }

    /**
     * Returns the identifier of the cover media item.
     *
     * @return int|null
     */
    public function getCoverMediaId(): ?int
    {
        return $this->coverMediaId;
    }

    /**
     * Sets the identifier of the cover media item.
     *
     * @param int|null $mediaId identifier of the media entity used as cover
     */
    public function setCoverMediaId(?int $mediaId): void
    {
        $this->coverMediaId = $mediaId;
    }

    /**
     * Returns the location associated with the cluster.
     *
     * @return Location|null
     */
    public function getLocation(): ?Location
    {
        return $this->location;
    }

    /**
     * Sets the location associated with the cluster.
     *
     * @param Location|null $location location assigned to the cluster
     */
    public function setLocation(?Location $location): void
    {
        $this->location = $location;
    }

    /**
     * Returns the algorithm version that produced the cluster.
     *
     * @return string|null
     */
    public function getAlgorithmVersion(): ?string
    {
        return $this->algorithmVersion;
    }

    /**
     * Sets the algorithm version that produced the cluster.
     *
     * @param string|null $version algorithm version label
     */
    public function setAlgorithmVersion(?string $version): void
    {
        $this->algorithmVersion = $version;
    }

    /**
     * Returns the configuration hash used during clustering.
     *
     * @return string|null
     */
    public function getConfigHash(): ?string
    {
        return $this->configHash;
    }

    /**
     * Sets the configuration hash used during clustering.
     *
     * @param string|null $hash hash of the configuration snapshot
     */
    public function setConfigHash(?string $hash): void
    {
        $this->configHash = $hash;
    }

    /**
     * Returns the cached latitude of the centroid.
     *
     * @return float|null
     */
    public function getCentroidLat(): ?float
    {
        return $this->centroidLat;
    }

    /**
     * Sets the cached latitude of the centroid.
     *
     * @param float|null $lat latitude of the centroid
     */
    public function setCentroidLat(?float $lat): void
    {
        $this->centroidLat = $lat;
    }

    /**
     * Returns the cached longitude of the centroid.
     *
     * @return float|null
     */
    public function getCentroidLon(): ?float
    {
        return $this->centroidLon;
    }

    /**
     * Sets the cached longitude of the centroid.
     *
     * @param float|null $lon longitude of the centroid
     */
    public function setCentroidLon(?float $lon): void
    {
        $this->centroidLon = $lon;
    }

    /**
     * Returns the S2 cell identifier of the centroid.
     *
     * @return string|null
     */
    public function getCentroidCell7(): ?string
    {
        return $this->centroidCell7;
    }

    /**
     * Sets the S2 cell identifier of the centroid.
     *
     * @param string|null $cell S2 cell identifier with level 7 precision
     */
    public function setCentroidCell7(?string $cell): void
    {
        $this->centroidCell7 = $cell;
    }
}
