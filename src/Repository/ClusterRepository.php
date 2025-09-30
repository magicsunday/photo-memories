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
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit);

        $query = $qb->getQuery();

        /** @var list<Cluster> $clusters */
        $clusters = $query->getResult();

        return $clusters;
    }
}
