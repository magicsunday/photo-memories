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
use MagicSunday\Memories\Service\Metadata\FacePresenceDetector;
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;

final class FacesStage extends AbstractExtractorStage
{
    /**
     * @var iterable<SingleMetadataExtractorInterface>
     */
    private readonly iterable $extractors;

    public function __construct(FacePresenceDetector $facePresenceDetector)
    {
        $this->extractors = [$facePresenceDetector];
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        if ($context->isSkipped()) {
            return $context;
        }

        if ($this->shouldSkipExtraction($context)) {
            return $context;
        }

        return $this->runExtractors($context, $this->extractors);
    }
}
