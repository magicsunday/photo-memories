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
use Doctrine\Persistence\ObjectRepository;
use InvalidArgumentException;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Entity\MediaDuplicate;

use function strcmp;

/**
 * Repository helper responsible for persisting perceptual duplicate pairs.
 */
readonly class MediaDuplicateRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Records or updates the perceptual distance between two media entities.
     *
     * @throws InvalidArgumentException When the provided media references are identical or the distance is invalid.
     */
    public function recordDistance(Media $first, Media $second, int $distance): MediaDuplicate
    {
        if ($distance < 0) {
            throw new InvalidArgumentException('Distance must be zero or greater.');
        }

        if ($first === $second) {
            throw new InvalidArgumentException('Duplicate detection requires distinct media instances.');
        }

        [$left, $right] = $this->normalisePair($first, $second);

        /** @var ObjectRepository<MediaDuplicate> $repository */
        $repository = $this->entityManager->getRepository(MediaDuplicate::class);

        $existing = $repository->findOneBy([
            'leftMedia'  => $left,
            'rightMedia' => $right,
        ]);

        if ($existing instanceof MediaDuplicate) {
            $existing->setDistance($distance);

            return $existing;
        }

        $duplicate = new MediaDuplicate($left, $right, $distance);
        $this->entityManager->persist($duplicate);

        return $duplicate;
    }

    /**
     * Ensures deterministic ordering of duplicate pairs based on media paths.
     *
     * @return array{0: Media, 1: Media}
     */
    private function normalisePair(Media $first, Media $second): array
    {
        if (strcmp($first->getPath(), $second->getPath()) <= 0) {
            return [$first, $second];
        }

        return [$second, $first];
    }
}
