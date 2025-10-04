<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing\Stage;

use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionStageInterface;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailServiceInterface;
use MagicSunday\Memories\Support\IndexLogHelper;
use Throwable;

use function sprintf;

final class ThumbnailGenerationStage implements MediaIngestionStageInterface
{
    public function __construct(
        private readonly ThumbnailServiceInterface $thumbnailService,
    ) {
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        if ($context->isSkipped() || $context->getMedia() === null || $context->shouldGenerateThumbnails() === false) {
            return $context;
        }

        try {
            $thumbnails = $this->thumbnailService->generateAll($context->getFilePath(), $context->getMedia());
            $context->getMedia()->setThumbnails($thumbnails);
        } catch (Throwable $exception) {
            $message = sprintf('Thumbnail generation failed for %s: %s', $context->getFilePath(), $exception->getMessage());

            $context->getOutput()->writeln(sprintf('<error>%s</error>', $message));
            IndexLogHelper::append($context->getMedia(), $message);
        }

        return $context;
    }
}
