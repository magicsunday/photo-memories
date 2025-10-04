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
use MagicSunday\Memories\Service\Metadata\AppleHeuristicsExtractor;
use MagicSunday\Memories\Service\Metadata\ExifMetadataExtractor;
use MagicSunday\Memories\Service\Metadata\FileStatMetadataExtractor;
use MagicSunday\Memories\Service\Metadata\FilenameKeywordExtractor;
use MagicSunday\Memories\Service\Metadata\FfprobeMetadataExtractor;
use MagicSunday\Memories\Service\Metadata\XmpIptcExtractor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MetadataStage extends AbstractExtractorStage
{
    /**
     * @var iterable<SingleMetadataExtractorInterface>
     */
    private readonly iterable $extractors;

    public function __construct(
        #[Autowire(service: ExifMetadataExtractor::class)]
        SingleMetadataExtractorInterface $exif,
        #[Autowire(service: XmpIptcExtractor::class)]
        SingleMetadataExtractorInterface $xmp,
        #[Autowire(service: FileStatMetadataExtractor::class)]
        SingleMetadataExtractorInterface $fileStat,
        #[Autowire(service: FilenameKeywordExtractor::class)]
        SingleMetadataExtractorInterface $filenameKeyword,
        #[Autowire(service: AppleHeuristicsExtractor::class)]
        SingleMetadataExtractorInterface $appleHeuristics,
        #[Autowire(service: FfprobeMetadataExtractor::class)]
        SingleMetadataExtractorInterface $ffprobe,
    ) {
        $this->extractors = [
            $exif,
            $xmp,
            $fileStat,
            $filenameKeyword,
            $appleHeuristics,
            $ffprobe,
        ];
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
