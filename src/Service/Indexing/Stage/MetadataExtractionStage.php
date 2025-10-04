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
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;

final class MetadataExtractionStage extends AbstractExtractorStage
{
    /**
     * @param iterable<SingleMetadataExtractorInterface> $extractors
     */
    public function __construct(
        private readonly iterable $extractors,
    ) {
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        if ($this->shouldSkipExtraction($context)) {
            return $context;
        }

        return $this->runExtractors($context, $this->extractors);
    }
}
