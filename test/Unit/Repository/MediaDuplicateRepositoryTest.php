<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MagicSunday\Memories\Entity\MediaDuplicate;
use MagicSunday\Memories\Repository\MediaDuplicateRepository;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MediaDuplicateRepositoryTest extends TestCase
{
    #[Test]
    public function recordDistancePersistsNewPairs(): void
    {
        $mediaA = $this->makeMedia(1, '/library/a.jpg');
        $mediaB = $this->makeMedia(2, '/library/b.jpg');

        $entityRepository = $this->createMock(EntityRepository::class);
        $entityRepository->expects(self::once())
            ->method('findOneBy')
            ->with([
                'leftMedia'  => $mediaA,
                'rightMedia' => $mediaB,
            ])
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(MediaDuplicate::class)
            ->willReturn($entityRepository);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (MediaDuplicate $duplicate) use ($mediaA, $mediaB): bool {
                return $duplicate->getLeftMedia() === $mediaA
                    && $duplicate->getRightMedia() === $mediaB
                    && $duplicate->getDistance() === 5;
            }));

        $repository = new MediaDuplicateRepository($entityManager);
        $result     = $repository->recordDistance($mediaA, $mediaB, 5);

        self::assertSame($mediaA, $result->getLeftMedia());
        self::assertSame($mediaB, $result->getRightMedia());
        self::assertSame(5, $result->getDistance());
    }

    #[Test]
    public function recordDistanceNormalisesPairOrdering(): void
    {
        $mediaA = $this->makeMedia(10, '/library/z.jpg');
        $mediaB = $this->makeMedia(11, '/library/a.jpg');

        $entityRepository = $this->createMock(EntityRepository::class);
        $entityRepository->expects(self::once())
            ->method('findOneBy')
            ->with([
                'leftMedia'  => $mediaB,
                'rightMedia' => $mediaA,
            ])
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(MediaDuplicate::class)
            ->willReturn($entityRepository);
        $entityManager->expects(self::once())->method('persist');

        $repository = new MediaDuplicateRepository($entityManager);
        $repository->recordDistance($mediaA, $mediaB, 3);
    }

    #[Test]
    public function recordDistanceUpdatesExistingDistance(): void
    {
        $mediaA = $this->makeMedia(21, '/library/a.jpg');
        $mediaB = $this->makeMedia(22, '/library/b.jpg');

        $existing = new MediaDuplicate($mediaA, $mediaB, 8);

        $entityRepository = $this->createMock(EntityRepository::class);
        $entityRepository->expects(self::once())
            ->method('findOneBy')
            ->with([
                'leftMedia'  => $mediaA,
                'rightMedia' => $mediaB,
            ])
            ->willReturn($existing);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(MediaDuplicate::class)
            ->willReturn($entityRepository);
        $entityManager->expects(self::never())->method('persist');

        $repository = new MediaDuplicateRepository($entityManager);
        $result     = $repository->recordDistance($mediaB, $mediaA, 2);

        self::assertSame($existing, $result);
        self::assertSame(2, $existing->getDistance());
        self::assertNotNull($existing->getUpdatedAt());
    }
}
