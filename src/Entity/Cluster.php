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
use MagicSunday\Memories\Utility\GeoCell;

use function count;
use function implode;
use function sha1;
use function sort;

use const SORT_NUMERIC;

/**
 * Cluster of related media grouped by a specific clustering algorithm.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'cluster',
    indexes: [
        new ORM\Index(name: 'idx_cluster_fingerprint', columns: ['fingerprint']),
        new ORM\Index(name: 'idx_cluster_start_at', columns: ['startAt']),
        new ORM\Index(name: 'idx_cluster_end_at', columns: ['endAt']),
        new ORM\Index(name: 'idx_cluster_members_count', columns: ['membersCount']),
        new ORM\Index(name: 'idx_cluster_cover_id', columns: ['cover_id']),
        new ORM\Index(name: 'idx_cluster_location_id', columns: ['location_id']),
        new ORM\Index(name: 'idx_cluster_centroid_cell7', columns: ['centroidCell7']),
        new ORM\Index(name: 'idx_cluster_config_hash', columns: ['configHash']),
    ]
)]
#[ORM\UniqueConstraint(name: 'uniq_cluster_algo_fp', columns: ['algorithm', 'fingerprint'])]
class Cluster
{
    /**
     * Surrogate identifier of the cluster persisted by Doctrine.
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private int $id;

    /**
     * Name of the clustering algorithm responsible for this cluster.
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $algorithm;

    /**
     * Configuration parameters used by the clustering algorithm.
     *
     * @var array<string, mixed> $params
     */
    #[ORM\Column(type: Types::JSON)]
    private array $params;

    /**
     * Geographic centroid of the cluster expressed as latitude/longitude pair.
     *
     * @var array{lat: float, lon: float} $centroid
     */
    #[ORM\Column(type: Types::JSON)]
    private array $centroid;

    /**
     * Identifiers of the media records that belong to this cluster.
     *
     * @var list<int> $members
     */
    #[ORM\Column(type: Types::JSON)]
    private array $members;

    /**
     * Timestamp that records when the cluster was first created.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * Order-insensitive hash derived from all member identifiers.
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $fingerprint;

    /**
     * Timestamp that marks the beginning of the cluster timeline.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startAt = null;

    /**
     * Timestamp that marks the end of the cluster timeline.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endAt = null;

    /**
     * Number of members contained in the cluster.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $membersCount = 0;

    /**
     * Number of photo members contained in the cluster.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $photoCount = null;

    /**
     * Number of video members contained in the cluster.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $videoCount = null;

    /**
     * Cover media chosen to represent the cluster.
     */
    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(name: 'cover_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Media $cover = null;

    /**
     * Dominant location representing the cluster.
     */
    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Location $location = null;

    /**
     * Semantic version of the clustering algorithm.
     */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $algorithmVersion = null;

    /**
     * Hash of the clustering configuration used to build the cluster.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $configHash = null;

    /**
     * Latitude component of the centroid stored redundantly for querying.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $centroidLat = null;

    /**
     * Longitude component of the centroid stored redundantly for querying.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $centroidLon = null;

    /**
     * Approximate spatial cell identifier for the centroid.
     */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $centroidCell7 = null;

    /**
     * @param string                        $algorithm algorithm used for clustering
     * @param array<string, mixed>          $params    parameters for the clustering run
     * @param array{lat: float, lon: float} $centroid  geographic centroid location
     * @param list<int>                     $members   media identifiers comprising the cluster
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
        $this->createdAt = new DateTimeImmutable();

        // Pre-compute the fingerprint to ensure deterministic cluster identity.
        $this->fingerprint  = self::computeFingerprint($this->members);
        $this->membersCount = count($members);
        $this->synchroniseCentroid($centroid);
    }

    /**
     * Updates derived centroid metadata.
     *
     * @param array{lat: float, lon: float} $centroid
     */
    private function synchroniseCentroid(array $centroid): void
    {
        $this->centroidLat = $centroid['lat'] ?? null;
        $this->centroidLon = $centroid['lon'] ?? null;
        $this->updateCentroidCell();
    }

    /**
     * Deterministic, order-independent member set hash.
     *
     * @param list<int> $members members that should be represented by the fingerprint
     *
     * @return string hash representing the member composition
     */
    public static function computeFingerprint(array $members): string
    {
        if (count($members) > 1) {
            // Sorting enforces order independence and yields stable hashes.
            sort($members, SORT_NUMERIC);
        }

        return sha1(implode(',', $members));
    }

    /**
     * Returns the persistent identifier of the cluster.
     *
     * @return int cluster identifier
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Returns the clustering algorithm name.
     *
     * @return string algorithm identifier
     */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Returns the algorithm parameters used to build the cluster.
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Returns the cluster centroid.
     *
     * @return array{lat: float, lon: float} latitude and longitude pair
     */
    public function getCentroid(): array
    {
        return $this->centroid;
    }

    /**
     * Stores the centroid and synchronises scalar projections.
     *
     * @param array{lat: float, lon: float} $value
     */
    public function setCentroid(array $value): void
    {
        $this->centroid = $value;
        $this->synchroniseCentroid($value);
    }

    /**
     * Returns the list of media identifiers associated with the cluster.
     *
     * @return list<int> media identifiers belonging to the cluster
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    /**
     * Returns the creation timestamp.
     *
     * @return DateTimeImmutable creation time of the cluster record
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the fingerprint that uniquely represents this cluster.
     *
     * @return string cluster fingerprint hash
     */
    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * Returns the start timestamp of the cluster timeline.
     */
    public function getStartAt(): ?DateTimeImmutable
    {
        return $this->startAt;
    }

    /**
     * Updates the start timestamp of the cluster timeline.
     */
    public function setStartAt(?DateTimeImmutable $startAt): void
    {
        $this->startAt = $startAt;
    }

    /**
     * Returns the end timestamp of the cluster timeline.
     */
    public function getEndAt(): ?DateTimeImmutable
    {
        return $this->endAt;
    }

    /**
     * Updates the end timestamp of the cluster timeline.
     */
    public function setEndAt(?DateTimeImmutable $endAt): void
    {
        $this->endAt = $endAt;
    }

    /**
     * Returns the number of members within the cluster.
     */
    public function getMembersCount(): int
    {
        return $this->membersCount;
    }

    /**
     * Sets the number of members contained in the cluster.
     */
    public function setMembersCount(int $count): void
    {
        $this->membersCount = $count;
    }

    /**
     * Returns the number of photo members if known.
     */
    public function getPhotoCount(): ?int
    {
        return $this->photoCount;
    }

    /**
     * Stores the number of photo members.
     */
    public function setPhotoCount(?int $photoCount): void
    {
        $this->photoCount = $photoCount;
    }

    /**
     * Returns the number of video members if known.
     */
    public function getVideoCount(): ?int
    {
        return $this->videoCount;
    }

    /**
     * Stores the number of video members.
     */
    public function setVideoCount(?int $videoCount): void
    {
        $this->videoCount = $videoCount;
    }

    /**
     * Returns the selected cover media entity.
     */
    public function getCover(): ?Media
    {
        return $this->cover;
    }

    /**
     * Updates the selected cover media entity.
     */
    public function setCover(?Media $cover): void
    {
        $this->cover = $cover;
    }

    /**
     * Returns the dominant location entity.
     */
    public function getLocation(): ?Location
    {
        return $this->location;
    }

    /**
     * Updates the dominant location entity.
     */
    public function setLocation(?Location $location): void
    {
        $this->location = $location;
    }

    /**
     * Returns the semantic version of the algorithm used.
     */
    public function getAlgorithmVersion(): ?string
    {
        return $this->algorithmVersion;
    }

    /**
     * Stores the semantic version of the algorithm used.
     */
    public function setAlgorithmVersion(?string $version): void
    {
        $this->algorithmVersion = $version;
    }

    /**
     * Returns the configuration hash used to generate the cluster.
     */
    public function getConfigHash(): ?string
    {
        return $this->configHash;
    }

    /**
     * Stores the configuration hash used to generate the cluster.
     */
    public function setConfigHash(?string $hash): void
    {
        $this->configHash = $hash;
    }

    /**
     * Returns the centroid latitude if available.
     */
    public function getCentroidLat(): ?float
    {
        return $this->centroidLat;
    }

    /**
     * Stores the centroid latitude and updates the derived cell key.
     */
    public function setCentroidLat(?float $lat): void
    {
        $this->centroidLat = $lat;
        $this->updateCentroidCell();
    }

    /**
     * Returns the centroid longitude if available.
     */
    public function getCentroidLon(): ?float
    {
        return $this->centroidLon;
    }

    /**
     * Stores the centroid longitude and updates the derived cell key.
     */
    public function setCentroidLon(?float $lon): void
    {
        $this->centroidLon = $lon;
        $this->updateCentroidCell();
    }

    /**
     * Returns the centroid cell identifier.
     */
    public function getCentroidCell7(): ?string
    {
        return $this->centroidCell7;
    }

    /**
     * Explicitly sets the centroid cell identifier.
     */
    public function setCentroidCell7(?string $cell): void
    {
        $this->centroidCell7 = $cell;
    }

    private function updateCentroidCell(): void
    {
        if ($this->centroidLat === null || $this->centroidLon === null) {
            $this->centroidCell7 = null;

            return;
        }

        $this->centroidCell7 = GeoCell::fromPoint($this->centroidLat, $this->centroidLon, 7);
    }
}
