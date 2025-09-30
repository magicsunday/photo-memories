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

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($media);
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::never())->method('clear');

        $stage = new PersistenceBatchStage($entityManager);
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
    }

    #[Test]
    public function processSkipsPersistenceDuringDryRun(): void
    {
        $media = new Media('file', 'checksum', 1);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $output  = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $stage   = new PersistenceBatchStage($entityManager);
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
}
