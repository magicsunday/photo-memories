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
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Stage\PersistenceBatchStage;
use MagicSunday\Memories\Service\Indexing\Support\PersistedMediaTracker;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class PersistenceBatchStageTest extends TestCase
{
    #[Test]
    public function processPersistsAndFlushesOnFinalize(): void
    {
        $media = new Media('file', 'checksum', 1);

        $tracker       = new PersistedMediaTracker();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($media);
        $entityManager->expects(self::once())->method('flush')->willReturnCallback(
            function () use ($media): void {
                $this->assignId($media, 42);
            }
        );
        $entityManager->expects(self::once())->method('detach')->with($media);

        $stage   = new PersistenceBatchStage($entityManager, 10, $tracker);
        $context = MediaIngestionContext::create(
            'file',
            false,
            false,
            false,
            false,
            new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE)
        )->withMedia($media);

        $stage->process($context);
        $stage->finalize(MediaIngestionContext::create('', false, false, false, false, new NullOutput()));

        self::assertSame([42], $tracker->drain());
    }

    #[Test]
    public function processSkipsPersistenceDuringDryRun(): void
    {
        $media = new Media('file', 'checksum', 1);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $output  = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $stage   = new PersistenceBatchStage($entityManager, 10, new PersistedMediaTracker());
        $context = MediaIngestionContext::create(
            'file',
            false,
            true,
            false,
            false,
            $output
        )->withMedia($media);

        $result = $stage->process($context);

        self::assertStringContainsString(' (dry-run) ', $output->fetch());
        self::assertSame($context, $result);
    }

    #[Test]
    public function processFlushesAfterConfiguredBatchSize(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted     = [];
        $entityManager->expects(self::exactly(3))->method('persist')->willReturnCallback(
            function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            }
        );
        $nextId  = 1;
        $counter = 0;
        $entityManager->expects(self::exactly(2))->method('flush')->willReturnCallback(
            function () use (&$persisted, &$nextId, &$counter): void {
                ++$counter;
                foreach ($persisted as $entity) {
                    if ($entity instanceof Media && $entity->getId() === null) {
                        $this->assignId($entity, $nextId++);
                    }
                }
            }
        );
        $entityManager->expects(self::exactly(3))->method('detach');

        $tracker = new PersistedMediaTracker();

        $stage = new PersistenceBatchStage($entityManager, 2, $tracker);

        for ($index = 0; $index < 3; ++$index) {
            $context = MediaIngestionContext::create(
                'file-' . $index,
                false,
                false,
                false,
                false,
                new NullOutput()
            )->withMedia(new Media('file-' . $index, 'checksum-' . $index, $index));

            $stage->process($context);
        }

        $stage->finalize(MediaIngestionContext::create('', false, false, false, false, new NullOutput()));

        self::assertSame(2, $counter);
        self::assertCount(3, $tracker->drain());
    }
}
