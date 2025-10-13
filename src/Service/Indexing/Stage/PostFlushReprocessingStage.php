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
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Indexing\Contract\FinalizableMediaIngestionStageInterface;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionStageInterface;
use MagicSunday\Memories\Service\Indexing\Support\PersistedMediaTracker;
use MagicSunday\Memories\Service\Metadata\BurstDetector;
use MagicSunday\Memories\Service\Metadata\LivePairLinker;

/**
 * Replays duplicate, burst and live-photo detection for freshly persisted media.
 */
final readonly class PostFlushReprocessingStage implements MediaIngestionStageInterface, FinalizableMediaIngestionStageInterface
{
    public function __construct(
        private PersistedMediaTracker $persistedMediaTracker,
        private MediaRepository $mediaRepository,
        private NearDuplicateStage $nearDuplicateStage,
        private BurstDetector $burstDetector,
        private LivePairLinker $livePairLinker,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        return $context;
    }

    public function finalize(MediaIngestionContext $context): void
    {
        $persistedIds = $this->persistedMediaTracker->drain();
        if ($persistedIds === [] || $context->isDryRun()) {
            return;
        }

        $medias = $this->mediaRepository->findByIds($persistedIds);

        if ($medias === []) {
            return;
        }

        foreach ($medias as $media) {
            $replayContext = $context->withMedia($media);
            $this->nearDuplicateStage->process($replayContext);

            if ($this->burstDetector->supports($media->getPath(), $media)) {
                $this->burstDetector->extract($media->getPath(), $media);
            }

            if ($this->livePairLinker->supports($media->getPath(), $media)) {
                $this->livePairLinker->extract($media->getPath(), $media);
            }
        }

        $this->entityManager->flush();

        foreach ($medias as $media) {
            $this->entityManager->detach($media);
        }
    }
}
