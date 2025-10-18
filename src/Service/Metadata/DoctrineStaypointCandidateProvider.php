<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Contract\StaypointCandidateProviderInterface;

use function max;

/**
 * Fetches staypoint candidates from the Doctrine ORM context.
 */
final readonly class DoctrineStaypointCandidateProvider implements StaypointCandidateProviderInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findCandidates(Media $seed, int $maxSamples = 500): array
    {
        $cell = $seed->getGeoCell8();
        if ($cell === null || $cell === '') {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('m')
            ->from(Media::class, 'm')
            ->where('m.geoCell8 = :cell')
            ->andWhere('m.takenAt IS NOT NULL')
            ->andWhere('m.noShow = false')
            ->setParameter('cell', $cell)
            ->orderBy('m.takenAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults(max(1, $maxSamples));

        /** @var list<Media> $items */
        $items = $qb->getQuery()->getResult();

        return $items;
    }
}
