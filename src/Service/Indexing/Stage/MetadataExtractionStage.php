<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing\Stage;

use DateTimeImmutable;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionStageInterface;
use MagicSunday\Memories\Service\Metadata\MetadataExtractorInterface;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
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

        $media = $context->getMedia();

        $media->setFeatureVersion(MetadataFeatureVersion::CURRENT);
        $media->setIndexedAt(new DateTimeImmutable());

        try {
            $media = $this->metadataExtractor->extract($context->getFilePath(), $media);

            $media->setFeatureVersion(MetadataFeatureVersion::CURRENT);
            $media->setIndexedAt(new DateTimeImmutable());
            $media->setIndexLog(null);

            return $context->withMedia($media);
        } catch (Throwable $exception) {
            $media->setFeatureVersion(MetadataFeatureVersion::CURRENT);
            $media->setIndexedAt(new DateTimeImmutable());
            $media->setIndexLog(sprintf('%s: %s', $exception::class, $exception->getMessage()));

            $context->getOutput()->writeln(
                sprintf('<error>Metadata extraction failed for %s: %s</error>', $context->getFilePath(), $exception->getMessage())
            );

            return $context->withMedia($media);
        }
    }
}
