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
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionStageInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function filesize;
use function hash_file;
use function sprintf;

final class DuplicateHandlingStage implements MediaIngestionStageInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        if ($context->isSkipped()) {
            return $context;
        }

        $checksum = @hash_file('sha256', $context->getFilePath());
        if ($checksum === false) {
            $context->getOutput()->writeln(
                sprintf('<error>Could not compute checksum for file: %s</error>', $context->getFilePath())
            );

            return $context->markSkipped();
        }

        $repository = $this->entityManager->getRepository(Media::class);
        $existing   = $repository->findOneBy(['checksum' => $checksum]);

        if ($existing instanceof Media && $context->isForce() === false) {
            $context->getOutput()->writeln(
                ' -> Übersprungen (bereits indexiert)',
                OutputInterface::VERBOSITY_VERBOSE
            );

            return $context->markSkipped();
        }

        $media = $existing ?? new Media(
            $context->getFilePath(),
            $checksum,
            filesize($context->getFilePath()) ?: 0,
        );

        $detectedMime = $context->getDetectedMime();
        if ($detectedMime !== null) {
            $media->setMime($detectedMime);
        }

        return $context
            ->withChecksum($checksum)
            ->withMedia($media);
    }
}
