<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ObjectRepository;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;

use function strlen;
use function strtolower;
use function str_pad;
use function substr;
use function trim;

/**
 * Minimal repository wrapper to load Media by IDs efficiently.
 */
readonly class MediaRepository implements MemberMediaLookupInterface
{
    public function __construct(private readonly EntityManagerInterface $em, private int $phashPrefixLength = 16)
    {
        if ($this->phashPrefixLength < 0) {
            $this->phashPrefixLength = 0;
        }
    }

    /**
     * @param list<int> $ids
     * @param bool      $onlyVideos
     *
     * @return list<Media>
     */
    public function findByIds(array $ids, bool $onlyVideos = false): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->where('m.id IN (:ids)')
            ->andWhere('m.noShow = false')
            ->andWhere('(m.burstRepresentative IS NULL OR m.burstRepresentative = true)')
            ->setParameter('ids', $ids);

        if ($onlyVideos) {
            $qb->andWhere('m.isVideo = true')
                ->orderBy('m.takenAt', 'ASC');
        }

        $q = $qb->getQuery();

        /** @var list<Media> $items */
        $items = $q->getResult();

        return $items;
    }

    /**
     * Finds media items within the provided Hamming distance of the given pHash.
     *
     * @param string $phashHex
     * @param int    $maxHamming
     * @param int    $limit
     *
     * @return list<array{media: Media, distance: int}>
     * @throws Exception
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function findNearestByPhash(string $phashHex, int $maxHamming, int $limit = 20): array
    {
        $phashHex = strtolower(trim($phashHex));
        if ($phashHex === '' || $maxHamming < 0) {
            return [];
        }

        $limit = max(
            $limit,
            1
        );

        $conn        = $this->em->getConnection();
        $prefixLength = min(
            $this->phashPrefixLength,
            32
        );
        $phashPrefix  = $prefixLength > 0 ? substr($phashHex, 0, $prefixLength) : '';
        $phash64Hex   = substr($phashHex, 0, 16);
        if (strlen($phash64Hex) < 16) {
            $phash64Hex = str_pad($phash64Hex, 16, '0');
        }

        $sql = <<<'SQL'
SELECT id,
       BIT_COUNT(
           CAST(CONV(HEX(phash64), 16, 10) AS UNSIGNED) ^
           CAST(CONV(:phashHex, 16, 10) AS UNSIGNED)
       ) AS hamming
FROM media
WHERE phash64 IS NOT NULL
  AND noShow = 0
  AND (phashPrefix = :phashPrefix OR :phashPrefix = '')
HAVING hamming <= :maxHamming
ORDER BY hamming ASC, id ASC
LIMIT :limit
SQL;

        $rows = $conn->fetchAllAssociative(
            $sql,
            [
                'phashHex'   => $phash64Hex,
                'phashPrefix'=> $phashPrefix,
                'maxHamming' => $maxHamming,
                'limit'      => $limit,
            ],
            [
                'phashHex'   => ParameterType::STRING,
                'phashPrefix'=> ParameterType::STRING,
                'maxHamming' => ParameterType::INTEGER,
                'limit'      => ParameterType::INTEGER,
            ]
        );

        if ($rows === []) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($id <= 0) {
                continue;
            }

            $media = $this->em->find(Media::class, $id);
            if (!$media instanceof Media) {
                continue;
            }

            $result[] = [
                'media'    => $media,
                'distance' => (int) ($row['hamming'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return list<Media>
     */
    public function findBurstMembers(string $burstUuid, ?string $excludePath = null): array
    {
        $burstUuid = trim($burstUuid);
        if ($burstUuid === '') {
            return [];
        }

        $qb = $this->em->createQueryBuilder();
        $qb->select('m')
            ->from(Media::class, 'm')
            ->where('m.burstUuid = :burstUuid')
            ->setParameter('burstUuid', $burstUuid)
            ->orderBy('m.burstIndex', 'ASC')
            ->addOrderBy('m.takenAt', 'ASC')
            ->addOrderBy('m.id', 'ASC');

        $excludePath = $excludePath !== null ? trim($excludePath) : null;
        if ($excludePath !== null && $excludePath !== '') {
            $qb->andWhere('m.path <> :excludePath')
                ->setParameter('excludePath', $excludePath);
        }

        $query = $qb->getQuery();

        /** @var list<Media> $items */
        $items = $query->getResult();

        return $items;
    }

    /**
     * @return list<Media>
     */
    public function findIndexingCandidates(int $limit): array
    {
        $maxResults = max(
            $limit,
            1
        );

        $qb = $this->em->createQueryBuilder();
        $qb->select('m')
            ->from(Media::class, 'm')
            ->where('m.noShow = false')
            ->andWhere('m.indexedAt IS NULL OR m.featureVersion < :pipelineVersion')
            ->setParameter('pipelineVersion', MetadataFeatureVersion::PIPELINE_VERSION)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($maxResults);

        $query = $qb->getQuery();

        /** @var list<Media> $items */
        $items = $query->getResult();

        return $items;
    }

    /**
     * @return list<Media>
     */
    public function findGoodCandidatesByDateRange(DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array
    {
        $maxResults = max(
            $limit,
            1
        );

        $qb = $this->em->createQueryBuilder();
        $qb->select('m')
            ->from(Media::class, 'm')
            ->where('m.noShow = false')
            ->andWhere('m.lowQuality = false')
            ->andWhere('(m.burstRepresentative IS NULL OR m.burstRepresentative = true)')
            ->andWhere('m.takenAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('m.takenAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults($maxResults);

        $query = $qb->getQuery();

        /** @var list<Media> $items */
        $items = $query->getResult();

        return $items;
    }

    /**
     * Attempts to find the counterpart of a live photo pair by checksum.
     */
    public function findLivePairCandidate(string $checksum, string $path): ?Media
    {
        $checksum = trim($checksum);
        $path     = trim($path);

        if ($checksum === '' || $path === '') {
            return null;
        }

        /** @var ObjectRepository<Media> $repo */
        $repo       = $this->em->getRepository(Media::class);
        $candidates = $repo->findBy(['livePairChecksum' => $checksum], ['id' => 'ASC'], 8);

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof Media) {
                continue;
            }

            if ($candidate->getPath() === $path || $candidate->isNoShow()) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
