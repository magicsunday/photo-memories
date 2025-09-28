<?php
/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use MagicSunday\Memories\Entity\WeatherObservation;

/**
 * @extends ServiceEntityRepository<WeatherObservation>
 */
final class WeatherObservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeatherObservation::class);
    }

    public function findOneByLookupHash(string $lookupHash): ?WeatherObservation
    {
        return $this->findOneBy(['lookupHash' => $lookupHash]);
    }

    public function existsByLookupHash(string $lookupHash): bool
    {
        $qb = $this->createQueryBuilder('w');
        $qb
            ->select('1')
            ->where('w.lookupHash = :lookupHash')
            ->setParameter('lookupHash', $lookupHash)
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }
}
