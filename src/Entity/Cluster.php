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
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use MagicSunday\Memories\Utility\GeoCell;

use function array_map;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function sha1;
use function sort;
use function trim;

use const SORT_NUMERIC;

/**
 * Cluster of related media grouped by a specific clustering algorithm.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'memories_cluster',
    indexes: [
        new ORM\Index(name: 'idx_memories_cluster_strategy_fingerprint', columns: ['strategy', 'fingerprint']),
        new ORM\Index(name: 'idx_memories_cluster_timerange', columns: ['type', 'start_at', 'end_at']),
        new ORM\Index(name: 'idx_memories_cluster_centroid', columns: ['centroid_lat', 'centroid_lon']),
        new ORM\Index(name: 'idx_memories_cluster_members_count', columns: ['members_count']),
        new ORM\Index(name: 'idx_memories_cluster_location_id', columns: ['location_id']),
        new ORM\Index(name: 'idx_memories_cluster_key_media_id', columns: ['key_media_id']),
        new ORM\Index(name: 'idx_memories_cluster_bbox', columns: ['bounding_box'], flags: ['gin']),
        new ORM\Index(name: 'idx_memories_cluster_meta', columns: ['meta'], flags: ['gin']),
        new ORM\Index(name: 'idx_memories_cluster_centroid_cell7', columns: ['centroid_cell7']),
    ]
)]
#[ORM\UniqueConstraint(name: 'uniq_memories_cluster_strategy_fp', columns: ['strategy', 'fingerprint'])]
#[ORM\HasLifecycleCallbacks]
class Cluster
{
    /**
     * Surrogate identifier of the cluster persisted by Doctrine.
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    /**
     * Logical cluster type describing the use-case (e.g. story, highlight, staypoint).
     */
    #[ORM\Column(name: 'type', type: Types::STRING, length: 64)]
    private string $type;

    /**
     * Name of the clustering algorithm responsible for this cluster.
     */
    #[ORM\Column(name: 'strategy', type: Types::STRING, length: 64)]
    private string $strategy;

    /**
     * Additional metadata stored alongside the cluster.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'meta', type: Types::JSON, nullable: true)]
    private array $meta = [];

    /**
     * Timestamp that records when the cluster was first created.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * Timestamp that records when the cluster was last updated.
     */
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /**
     * Order-insensitive hash derived from all member identifiers.
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $fingerprint;

    /**
     * Timestamp that marks the beginning of the cluster timeline.
     */
    #[ORM\Column(name: 'start_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startAt = null;

    /**
     * Timestamp that marks the end of the cluster timeline.
     */
    #[ORM\Column(name: 'end_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endAt = null;

    /**
     * Number of members contained in the cluster.
     */
    #[ORM\Column(name: 'members_count', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $membersCount = 0;

    /**
     * Number of photo members contained in the cluster.
     */
    #[ORM\Column(name: 'photo_count', type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $photoCount = null;

    /**
     * Number of video members contained in the cluster.
     */
    #[ORM\Column(name: 'video_count', type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $videoCount = null;

    /**
     * Media chosen to represent the cluster (hero/key visual).
     */
    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(name: 'key_media_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Media $keyMedia = null;

    /**
     * Dominant location representing the cluster.
     */
    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Location $location = null;

    /**
     * Semantic version of the clustering algorithm.
     */
    #[ORM\Column(name: 'algorithm_version', type: Types::STRING, length: 32, nullable: true)]
    private ?string $algorithmVersion = null;

    /**
     * Hash of the clustering configuration used to build the cluster.
     */
    #[ORM\Column(name: 'config_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $configHash = null;

    /**
     * Latitude component of the centroid stored redundantly for querying.
     */
    #[ORM\Column(name: 'centroid_lat', type: Types::FLOAT, nullable: true)]
    private ?float $centroidLat = null;

    /**
     * Longitude component of the centroid stored redundantly for querying.
     */
    #[ORM\Column(name: 'centroid_lon', type: Types::FLOAT, nullable: true)]
    private ?float $centroidLon = null;

    /**
     * Approximate spatial cell identifier for the centroid.
     */
    #[ORM\Column(name: 'centroid_cell7', type: Types::STRING, length: 32, nullable: true)]
    private ?string $centroidCell7 = null;

    /**
     * Bounding box geometry persisted as GeoJSON polygon.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'bounding_box', type: Types::JSON, nullable: true)]
    private ?array $boundingBox = null;

    /**
     * Final weighted score used for ordering.
     */
    #[ORM\Column(name: 'score', type: Types::FLOAT, nullable: true)]
    private ?float $score = null;

    /**
     * Pre-normalised score provided by the scoring pipeline.
     */
    #[ORM\Column(name: 'score_pre_norm', type: Types::FLOAT, nullable: true)]
    private ?float $scorePreNorm = null;

    /**
     * Post-normalised score prior to boosts.
     */
    #[ORM\Column(name: 'score_post_norm', type: Types::FLOAT, nullable: true)]
    private ?float $scorePostNorm = null;

    /**
     * Boosted score reflecting algorithmic adjustments.
     */
    #[ORM\Column(name: 'score_boosted', type: Types::FLOAT, nullable: true)]
    private ?float $scoreBoosted = null;

    /**
     * Average media quality score for the cluster.
     */
    #[ORM\Column(name: 'quality_score', type: Types::FLOAT, nullable: true)]
    private ?float $qualityScore = null;

    /**
     * Aggregated people coverage score for the cluster.
     */
    #[ORM\Column(name: 'people_score', type: Types::FLOAT, nullable: true)]
    private ?float $peopleScore = null;

    /**
     * Persisted cluster members with ordering and per-item metadata.
     *
     * @var Collection<int, ClusterMember>
     */
    #[ORM\OneToMany(mappedBy: 'cluster', targetEntity: ClusterMember::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordering' => 'ASC'])]
    private Collection $clusterMembers;

    /**
     * @param string                        $type      logical cluster type
     * @param string                        $strategy  algorithm used for clustering
     * @param array<string, mixed>          $params    parameters for the clustering run
     * @param array{lat: float, lon: float} $centroid  geographic centroid location
     * @param list<int>                     $members   media identifiers comprising the cluster
     */
    public function __construct(
        string $type,
        string $strategy,
        array $params,
        array $centroid,
        array $members,
    ) {
        $this->type     = $type !== '' ? trim($type) : 'story';
        $this->strategy = $strategy;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->clusterMembers = new ArrayCollection();

        $this->setParams($params);
        $this->setMembers($members);
        $this->setCentroid($centroid);
    }

    /**
     * Updates derived centroid metadata.
     *
     * @param array{lat: float, lon: float} $centroid
     */
    private function synchroniseCentroid(array $centroid): void
    {
        $this->centroidLat = isset($centroid['lat']) ? (float) $centroid['lat'] : null;
        $this->centroidLon = isset($centroid['lon']) ? (float) $centroid['lon'] : null;
        $this->meta['centroid'] = [
            'lat' => $this->centroidLat,
            'lon' => $this->centroidLon,
        ];
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
        return $this->id ?? 0;
    }

    /**
     * Returns the logical cluster type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Updates the cluster type label.
     */
    public function setType(string $type): void
    {
        $type = trim($type);
        $this->type = $type !== '' ? $type : 'story';
        $this->touch();
    }

    /**
     * Returns the clustering algorithm name.
     *
     * @return string algorithm identifier
     */
    public function getAlgorithm(): string
    {
        return $this->strategy;
    }

    /**
     * Returns the configured clustering strategy identifier.
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Returns the algorithm parameters used to build the cluster.
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        $params = $this->meta['params'] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Replaces the algorithm parameters associated with the cluster.
     *
     * @param array<string, mixed> $params
     */
    public function setParams(array $params): void
    {
        $this->meta['params'] = $params;
        $this->touch();
    }

    /**
     * Returns the cluster centroid.
     *
     * @return array{lat: float, lon: float} latitude and longitude pair
     */
    public function getCentroid(): array
    {
        $centroid = $this->meta['centroid'] ?? null;
        if (is_array($centroid) && isset($centroid['lat'], $centroid['lon'])) {
            return [
                'lat' => (float) $centroid['lat'],
                'lon' => (float) $centroid['lon'],
            ];
        }

        return [
            'lat' => $this->centroidLat ?? 0.0,
            'lon' => $this->centroidLon ?? 0.0,
        ];
    }

    /**
     * Stores the centroid and synchronises scalar projections.
     *
     * @param array{lat: float, lon: float} $value
     */
    public function setCentroid(array $value): void
    {
        $this->synchroniseCentroid($value);
        $this->touch();
    }

    /**
     * Returns the list of media identifiers associated with the cluster.
     *
     * @return list<int> media identifiers belonging to the cluster
     */
    public function getMembers(): array
    {
        $ids = $this->meta['member_ids'] ?? [];
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $value): int => (int) $value, $ids));
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

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
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
        $this->touch();
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
        $this->touch();
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
        $this->touch();
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
        $this->touch();
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
        $this->touch();
    }

    /**
     * Returns the selected cover media entity.
     */
    public function getCover(): ?Media
    {
        return $this->keyMedia;
    }

    /**
     * Updates the selected cover media entity.
     */
    public function setCover(?Media $cover): void
    {
        $this->keyMedia = $cover;
        $this->touch();
    }

    /**
     * Returns the key media used to represent the cluster.
     */
    public function getKeyMedia(): ?Media
    {
        return $this->keyMedia;
    }

    /**
     * Sets the key media representing the cluster.
     */
    public function setKeyMedia(?Media $media): void
    {
        $this->keyMedia = $media;
        $this->touch();
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
        $this->touch();
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
        $this->touch();
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
        $this->touch();
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
        $this->meta['centroid']['lat'] = $lat;
        $this->updateCentroidCell();
        $this->touch();
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
        $this->meta['centroid']['lon'] = $lon;
        $this->updateCentroidCell();
        $this->touch();
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
        $this->touch();
    }

    /**
     * Returns the persisted bounding box geometry.
     *
     * @return array<string, mixed>|null
     */
    public function getBoundingBox(): ?array
    {
        return $this->boundingBox;
    }

    /**
     * Stores the persisted bounding box geometry.
     *
     * @param array<string, mixed>|null $boundingBox
     */
    public function setBoundingBox(?array $boundingBox): void
    {
        $this->boundingBox = $boundingBox;
        $this->touch();
    }

    /**
     * Returns the final weighted score.
     */
    public function getScore(): ?float
    {
        return $this->score;
    }

    /**
     * Updates the final weighted score.
     */
    public function setScore(?float $score): void
    {
        $this->score = $score;
        $this->touch();
    }

    public function getScorePreNorm(): ?float
    {
        return $this->scorePreNorm;
    }

    public function setScorePreNorm(?float $scorePreNorm): void
    {
        $this->scorePreNorm = $scorePreNorm;
        $this->touch();
    }

    public function getScorePostNorm(): ?float
    {
        return $this->scorePostNorm;
    }

    public function setScorePostNorm(?float $scorePostNorm): void
    {
        $this->scorePostNorm = $scorePostNorm;
        $this->touch();
    }

    public function getScoreBoosted(): ?float
    {
        return $this->scoreBoosted;
    }

    public function setScoreBoosted(?float $scoreBoosted): void
    {
        $this->scoreBoosted = $scoreBoosted;
        $this->touch();
    }

    public function getQualityScore(): ?float
    {
        return $this->qualityScore;
    }

    public function setQualityScore(?float $qualityScore): void
    {
        $this->qualityScore = $qualityScore;
        $this->touch();
    }

    public function getPeopleScore(): ?float
    {
        return $this->peopleScore;
    }

    public function setPeopleScore(?float $peopleScore): void
    {
        $this->peopleScore = $peopleScore;
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

        $params = $this->meta['params'] ?? [];
        if (is_array($params)) {
            $this->meta['params'] = $params;
        } else {
            $this->meta['params'] = [];
        }

        $members = $this->meta['member_ids'] ?? [];
        if (is_array($members)) {
            $this->setMembers($members);
        }

        $centroid = $this->meta['centroid'] ?? null;
        if (is_array($centroid)) {
            $this->synchroniseCentroid($centroid);
        }

        $this->touch();
    }

    /**
     * @param list<int> $members
     */
    public function setMembers(array $members): void
    {
        $normalized = array_values(array_map(static fn (mixed $value): int => (int) $value, $members));
        $this->meta['member_ids'] = $normalized;
        $this->membersCount       = count($normalized);
        $this->fingerprint        = self::computeFingerprint($normalized);
        $this->touch();
    }

    /**
     * @return Collection<int, ClusterMember>
     */
    public function getClusterMembers(): Collection
    {
        return $this->clusterMembers;
    }

    public function addClusterMember(ClusterMember $member): void
    {
        if ($this->clusterMembers->contains($member)) {
            return;
        }

        $this->clusterMembers->add($member);
        $member->setCluster($this);
        $this->touch();
    }

    public function clearClusterMembers(): void
    {
        foreach ($this->clusterMembers->toArray() as $member) {
            $this->clusterMembers->removeElement($member);
        }

        $this->touch();
    }

    public function removeClusterMember(ClusterMember $member): void
    {
        if ($this->clusterMembers->removeElement($member)) {
            $this->touch();
        }
    }

    private function updateCentroidCell(): void
    {
        if ($this->centroidLat === null || $this->centroidLon === null) {
            $this->centroidCell7 = null;

            return;
        }

        $this->centroidCell7 = GeoCell::fromPoint($this->centroidLat, $this->centroidLon, 7);
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
