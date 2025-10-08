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
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;
use Throwable;

use function sprintf;
use function trim;

/**
 * Class AbstractExtractorStage.
 */
abstract class AbstractExtractorStage implements MediaIngestionStageInterface
{
    protected function shouldSkipExtraction(MediaIngestionContext $context): bool
    {
        if ($context->isSkipped()) {
            return true;
        }

        $media = $context->getMedia();
        if ($media === null) {
            return true;
        }

        if ($context->isForce() === false
            && $context->requiresReindex() === false
            && $media->getFeatureVersion() === MetadataFeatureVersion::PIPELINE_VERSION
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param iterable<SingleMetadataExtractorInterface> $extractors
     */
    protected function runExtractors(MediaIngestionContext $context, iterable $extractors): MediaIngestionContext
    {
        $media = $context->getMedia();
        if ($media === null) {
            return $context;
        }

        $media->setIndexLog(null);

        foreach ($extractors as $extractor) {
            if ($extractor->supports($context->getFilePath(), $media) === false) {
                continue;
            }

            try {
                $media = $extractor->extract($context->getFilePath(), $media);
            } catch (Throwable $exception) {
                $media->setFeatureVersion(MetadataFeatureVersion::PIPELINE_VERSION);
                $media->setIndexedAt(new DateTimeImmutable());

                $exceptionMessage = trim($exception->getMessage());
                if ($exceptionMessage === '') {
                    $media->setIndexLog(null);
                } else {
                    $media->setIndexLog(sprintf('%s: %s', $exception::class, $exceptionMessage));
                }

                $context->getOutput()->writeln(
                    sprintf('<error>Metadata extraction failed for %s: %s</error>', $context->getFilePath(), $exception->getMessage())
                );

                return $context->withMedia($media);
            }
        }

        $media->setFeatureVersion(MetadataFeatureVersion::PIPELINE_VERSION);
        $media->setIndexedAt(new DateTimeImmutable());

        $log = $media->getIndexLog();
        if ($log === null || $log === '') {
            $media->setIndexLog(null);
        }

        return $context->withMedia($media);
    }
}
