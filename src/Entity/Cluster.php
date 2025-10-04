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

use function count;
use function implode;
use function sha1;
use function sort;

use const SORT_NUMERIC;

/**
 * Cluster of related media grouped by a specific clustering algorithm.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cluster')]
#[ORM\UniqueConstraint(name: 'uniq_cluster_algo_fp', columns: ['algorithm', 'fingerprint'])]
#[ORM\Index(name: 'idx_cluster_fingerprint', columns: ['fingerprint'])]
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
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $params;

    /**
     * Geographic centroid of the cluster expressed as latitude/longitude pair.
     *
     * @var array{lat: float, lon: float}
     */
    #[ORM\Column(type: Types::JSON)]
    private array $centroid;

    /**
     * Identifiers of the media records that belong to this cluster.
     *
     * @var list<int>
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
    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $fingerprint;

    /**
     * @param string              $algorithm Algorithm used for clustering.
     * @param array<string, mixed> $params   Parameters for the clustering run.
     * @param array{lat: float, lon: float} $centroid Geographic centroid location.
     * @param list<int>           $members   Media identifiers comprising the cluster.
     */
    public function __construct(
        string $algorithm,
        array $params,
        array $centroid,
        array $members,
    ) {
        $this->algorithm   = $algorithm;
        $this->params      = $params;
        $this->centroid    = $centroid;
        $this->members     = $members;
        $this->createdAt   = new DateTimeImmutable();

        // Pre-compute the fingerprint to ensure deterministic cluster identity.
        $this->fingerprint = self::computeFingerprint($this->members);
    }

    /**
     * Deterministic, order-independent member set hash.
     *
     * @param list<int> $members Members that should be represented by the fingerprint.
     *
     * @return string Hash representing the member composition.
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
     * @return int Cluster identifier.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Returns the clustering algorithm name.
     *
     * @return string Algorithm identifier.
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
     * @return array{lat: float, lon: float} Latitude and longitude pair.
     */
    public function getCentroid(): array
    {
        return $this->centroid;
    }

    /**
     * Returns the list of media identifiers associated with the cluster.
     *
     * @return list<int> Media identifiers belonging to the cluster.
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    /**
     * Returns the creation timestamp.
     *
     * @return DateTimeImmutable Creation time of the cluster record.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the fingerprint that uniquely represents this cluster.
     *
     * @return string Cluster fingerprint hash.
     */
    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }
}
