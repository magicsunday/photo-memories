<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing\Stage;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Service\Indexing\Contract\FinalizableMediaIngestionStageInterface;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use Symfony\Component\Console\Output\OutputInterface;

final class PersistenceBatchStage implements FinalizableMediaIngestionStageInterface
{
    private const BATCH_SIZE = 10;

    private int $batchCount = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        if ($context->isSkipped() || $context->getMedia() === null) {
            return $context;
        }

        if ($context->isDryRun()) {
            $context->getOutput()->writeln(' (dry-run) ', OutputInterface::VERBOSITY_VERBOSE);

            return $context;
        }

        $this->entityManager->persist($context->getMedia());
        ++$this->batchCount;

        if ($this->batchCount >= self::BATCH_SIZE) {
            $this->entityManager->flush();
            $this->entityManager->clear();
            $this->batchCount = 0;
        }

        return $context;
    }

    public function finalize(MediaIngestionContext $context): void
    {
        if ($context->isDryRun()) {
            $this->batchCount = 0;

            return;
        }

        $this->entityManager->flush();
        $this->batchCount = 0;
    }
}
