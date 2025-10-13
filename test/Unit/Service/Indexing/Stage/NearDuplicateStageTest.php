<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Indexing\Stage;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\MediaDuplicate;
use MagicSunday\Memories\Repository\MediaDuplicateRepository;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Stage\NearDuplicateStage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;

final class NearDuplicateStageTest extends TestCase
{
    #[Test]
    public function processPersistsDuplicateDistances(): void
    {
        $primary = $this->makeMedia(10, '/library/a.jpg');
        $primary->setPhash('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        $secondary = $this->makeMedia(11, '/library/b.jpg');

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository->expects(self::once())
            ->method('findNearestByPhash')
            ->with('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 6)
            ->willReturn([
                ['media' => $primary, 'distance' => 0],
                ['media' => $secondary, 'distance' => 4],
            ]);

        $duplicateRepository = $this->createMock(MediaDuplicateRepository::class);
        $duplicateRepository->expects(self::once())
            ->method('recordDistance')
            ->with($primary, $secondary, 4)
            ->willReturn(new MediaDuplicate($primary, $secondary, 4));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('contains')
            ->with($primary)
            ->willReturn(true);

        $stage = new NearDuplicateStage($entityManager, $mediaRepository, $duplicateRepository);

        $context = MediaIngestionContext::create(
            $primary->getPath(),
            false,
            false,
            false,
            false,
            new BufferedOutput()
        )->withMedia($primary);

        $result = $stage->process($context);

        self::assertSame($primary, $result->getMedia());
    }

    #[Test]
    public function processSkipsPersistenceDuringDryRun(): void
    {
        $primary = $this->makeMedia(20, '/library/dry-run.jpg');
        $primary->setPhash('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $secondary = $this->makeMedia(21, '/library/dry-run-2.jpg');

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository->expects(self::once())
            ->method('findNearestByPhash')
            ->with('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 6)
            ->willReturn([
                ['media' => $secondary, 'distance' => 2],
            ]);

        $duplicateRepository = $this->createMock(MediaDuplicateRepository::class);
        $duplicateRepository->expects(self::never())->method('recordDistance');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('contains')
            ->with($primary)
            ->willReturn(true);

        $stage = new NearDuplicateStage($entityManager, $mediaRepository, $duplicateRepository);

        $context = MediaIngestionContext::create(
            $primary->getPath(),
            false,
            true,
            false,
            false,
            new BufferedOutput()
        )->withMedia($primary);

        $result = $stage->process($context);

        self::assertSame($primary, $result->getMedia());
    }

    #[Test]
    public function processDoesNotQueryRepositoryWhenPhashMissing(): void
    {
        $primary = $this->makeMedia(30, '/library/no-hash.jpg');

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository->expects(self::never())->method('findNearestByPhash');

        $duplicateRepository = $this->createMock(MediaDuplicateRepository::class);
        $duplicateRepository->expects(self::never())->method('recordDistance');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('contains')
            ->with($primary)
            ->willReturn(true);

        $stage = new NearDuplicateStage($entityManager, $mediaRepository, $duplicateRepository);

        $context = MediaIngestionContext::create(
            $primary->getPath(),
            false,
            false,
            false,
            false,
            new BufferedOutput()
        )->withMedia($primary);

        $result = $stage->process($context);

        self::assertSame($primary, $result->getMedia());
    }

    #[Test]
    public function processSkipsUnmanagedMedia(): void
    {
        $primary = $this->makeMedia(40, '/library/unmanaged.jpg');
        $primary->setPhash('cccccccccccccccccccccccccccccccc');

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository->expects(self::never())->method('findNearestByPhash');

        $duplicateRepository = $this->createMock(MediaDuplicateRepository::class);
        $duplicateRepository->expects(self::never())->method('recordDistance');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('contains')
            ->with($primary)
            ->willReturn(false);

        $stage = new NearDuplicateStage($entityManager, $mediaRepository, $duplicateRepository);

        $context = MediaIngestionContext::create(
            $primary->getPath(),
            false,
            false,
            false,
            false,
            new BufferedOutput()
        )->withMedia($primary);

        $result = $stage->process($context);

        self::assertSame($primary, $result->getMedia());
    }
}
