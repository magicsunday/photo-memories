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
use MagicSunday\Memories\Service\Metadata\MetadataExtractorInterface;
use Throwable;

use function sprintf;

final class MetadataExtractionStage implements MediaIngestionStageInterface
{
    public function __construct(
        private readonly MetadataExtractorInterface $metadataExtractor,
    ) {
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        if ($context->isSkipped() || $context->getMedia() === null) {
            return $context;
        }

        try {
            $media = $this->metadataExtractor->extract($context->getFilePath(), $context->getMedia());
        } catch (Throwable $exception) {
            $context->getOutput()->writeln(
                sprintf('<error>Metadata extraction failed for %s: %s</error>', $context->getFilePath(), $exception->getMessage())
            );

            return $context;
        }

        return $context->withMedia($media);
    }
}
