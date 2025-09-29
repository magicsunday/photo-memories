<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cluster')]
#[ORM\UniqueConstraint(name: 'uniq_cluster_algo_fp', columns: ['algorithm', 'fingerprint'])]
#[ORM\Index(name: 'idx_cluster_fingerprint', columns: ['fingerprint'])]
class Cluster
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $algorithm;

    #[ORM\Column(type: Types::JSON)]
    private array $params;

    /** @var array{lat: float, lon: float} */
    #[ORM\Column(type: Types::JSON)]
    private array $centroid;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $members;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $fingerprint;

    public function __construct(
        string $algorithm,
        array $params,
        array $centroid,
        array $members
    ) {
        $this->algorithm = $algorithm;
        $this->params = $params;
        $this->centroid = $centroid;
        $this->members = $members;
        $this->createdAt = new DateTimeImmutable();
        $this->fingerprint = self::computeFingerprint($this->members);
    }

    /**
     * Deterministic, order-independent member set hash.
     *
     * @param list<int> $members
     */
    public static function computeFingerprint(array $members): string
    {
        if (\count($members) > 1) {
            \sort($members, \SORT_NUMERIC);
        }

        return \sha1(\implode(',', $members));
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @return array
     */
    public function getCentroid(): array
    {
        return $this->centroid;
    }

    /**
     * @return array
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    /**
     * @return DateTimeImmutable
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
