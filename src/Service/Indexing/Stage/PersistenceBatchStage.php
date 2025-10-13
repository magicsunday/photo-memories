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
use InvalidArgumentException;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\FinalizableMediaIngestionStageInterface;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Support\PersistedMediaTracker;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class PersistenceBatchStage.
 */
final class PersistenceBatchStage implements FinalizableMediaIngestionStageInterface
{
    private int $batchCount = 0;

    /**
     * @var list<Media>
     */
    private array $pendingMedia = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly int $batchSize,
        private readonly PersistedMediaTracker $persistedMediaTracker,
    ) {
        if ($batchSize <= 0) {
            throw new InvalidArgumentException('Batch size must be greater than zero.');
        }
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

        $media = $context->getMedia();
        if (!$media instanceof Media) {
            return $context;
        }

        $this->entityManager->persist($media);
        ++$this->batchCount;
        $this->pendingMedia[] = $media;

        if ($this->batchCount >= $this->batchSize) {
            $this->flushPendingMedia();
        }

        return $context;
    }

    public function finalize(MediaIngestionContext $context): void
    {
        if ($context->isDryRun()) {
            $this->batchCount = 0;
            $this->pendingMedia = [];
            $this->persistedMediaTracker->clear();

            return;
        }

        $this->flushPendingMedia();
    }

    private function flushPendingMedia(): void
    {
        if ($this->pendingMedia === []) {
            return;
        }

        foreach ($this->pendingMedia as $media) {
            $this->entityManager->flush($media);

            $id = $media->getId();
            if ($id !== null) {
                $this->persistedMediaTracker->record($id);
            }

            $this->entityManager->detach($media);
        }

        $this->pendingMedia = [];
        $this->batchCount   = 0;
    }
}
