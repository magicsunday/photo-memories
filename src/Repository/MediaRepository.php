<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Repository;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;

use function strtolower;
use function substr;
use function trim;

/**
 * Minimal repository wrapper to load Media by IDs efficiently.
 */
readonly class MediaRepository implements MemberMediaLookupInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
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
     * @return list<array{media: Media, distance: int}>
     */
    public function findNearestByPhash(string $phashHex, int $maxHamming, int $limit = 20): array
    {
        $phashHex = trim(strtolower($phashHex));
        if ($phashHex === '' || $maxHamming < 0) {
            return [];
        }

        $limit = $limit < 1 ? 1 : $limit;

        $conn        = $this->em->getConnection();
        $phashPrefix = substr($phashHex, 0, 32);

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
                'phashHex'   => $phashHex,
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
