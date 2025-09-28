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
use MagicSunday\Memories\Entity\WeatherObservation;

/**
 * Repository helpers for weather observations.
 */
final class WeatherObservationRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findOneByLookupHash(string $lookupHash): ?WeatherObservation
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb
            ->select('w')
            ->from(WeatherObservation::class, 'w')
            ->where('w.lookupHash = :lookupHash')
            ->setParameter('lookupHash', $lookupHash)
            ->setMaxResults(1);

        /** @var WeatherObservation|null $result */
        $result = $qb->getQuery()->getOneOrNullResult();

        return $result;
    }

    public function existsByLookupHash(string $lookupHash): bool
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb
            ->select('1')
            ->from(WeatherObservation::class, 'w')
            ->where('w.lookupHash = :lookupHash')
            ->setParameter('lookupHash', $lookupHash)
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }
}
