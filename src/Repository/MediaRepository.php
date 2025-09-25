<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Repository;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Media;

/**
 * Minimal repository wrapper to load Media by IDs efficiently.
 */
final class MediaRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @param list<int> $ids
     * @return list<Media>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids);

        $q = $qb->getQuery();

        /** @var list<Media> $items */
        $items = $q->getResult();
        return $items;
    }
}
