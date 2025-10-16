<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Repository;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Cluster;

use function array_unique;
use function array_values;
use function max;

/**
 * Repository helper to load recently created clusters.
 */
readonly class ClusterRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return list<Cluster>
     */
    public function findLatest(int $limit): array
    {
        $limit = max(1, $limit);

        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Cluster::class, 'c')
            ->orderBy('c.startAt', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit);

        $query = $qb->getQuery();

        /** @var list<Cluster> $clusters */
        $clusters = $query->getResult();

        return $clusters;
    }

    public function countByAlgorithms(?array $algorithms = null): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Cluster::class, 'c');

        if ($algorithms !== null && $algorithms !== []) {
            $qb->where('c.algorithm IN (:algorithms)')
                ->setParameter('algorithms', array_values(array_unique($algorithms)));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return iterable<Cluster>
     */
    public function iterateByAlgorithms(?array $algorithms = null): iterable
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Cluster::class, 'c')
            ->orderBy('c.id', 'ASC');

        if ($algorithms !== null && $algorithms !== []) {
            $qb->where('c.algorithm IN (:algorithms)')
                ->setParameter('algorithms', array_values(array_unique($algorithms)));
        }

        $query = $qb->getQuery();

        foreach ($query->toIterable() as $cluster) {
            if ($cluster instanceof Cluster) {
                yield $cluster;
            }
        }
    }
}
