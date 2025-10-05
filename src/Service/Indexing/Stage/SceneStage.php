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
use MagicSunday\Memories\Service\Metadata\ClipSceneTagExtractor;
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Class SceneStage
 */
final class SceneStage extends AbstractExtractorStage
{
    /**
     * @var iterable<SingleMetadataExtractorInterface>
     */
    private readonly iterable $extractors;

    public function __construct(
        #[Autowire(service: ClipSceneTagExtractor::class)]
        SingleMetadataExtractorInterface $sceneTagExtractor,
    ) {
        $this->extractors = [$sceneTagExtractor];
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
