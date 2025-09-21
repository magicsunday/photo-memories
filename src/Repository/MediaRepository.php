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

        $q = $this->em->createQuery(
            'SELECT m FROM MagicSunday\Memories\Entity\Media m WHERE m.id IN (:ids)'
        );
        $q->setParameter('ids', $ids);

        /** @var list<Media> $items */
        $items = $q->getResult();
        return $items;
    }
}
