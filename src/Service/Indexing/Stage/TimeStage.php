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
use MagicSunday\Memories\Service\Metadata\CalendarFeatureEnricher;
use MagicSunday\Memories\Service\Metadata\MetadataQaInspector;
use MagicSunday\Memories\Service\Metadata\MetadataQaReportCollector;
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;
use MagicSunday\Memories\Service\Metadata\TimeNormalizer;
use MagicSunday\Memories\Support\IndexLogHelper;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Class TimeStage.
 */
final class TimeStage extends AbstractExtractorStage
{
    /**
     * @var iterable<SingleMetadataExtractorInterface>
     */
    private readonly iterable $extractors;

    private readonly MetadataQaInspector $metadataQaInspector;

    private readonly MetadataQaReportCollector $qaReportCollector;

    public function __construct(
        #[Autowire(service: TimeNormalizer::class)]
        SingleMetadataExtractorInterface $normalizer,
        #[Autowire(service: CalendarFeatureEnricher::class)]
        SingleMetadataExtractorInterface $calendar,
        SingleMetadataExtractorInterface $daypart,
        SingleMetadataExtractorInterface $solar,
        MetadataQaInspector $metadataQaInspector,
        MetadataQaReportCollector $qaReportCollector,
    ) {
        $this->extractors = [
            $normalizer,
            $calendar,
            $daypart,
            $solar,
        ];

        $this->metadataQaInspector  = $metadataQaInspector;
        $this->qaReportCollector    = $qaReportCollector;
    }

    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        if ($context->isSkipped()) {
            return $context;
        }

        if ($this->shouldSkipExtraction($context)) {
            return $context;
        }

        $context = $this->runExtractors($context, $this->extractors);

        $media = $context->getMedia();
        if ($media === null) {
            return $context;
        }

        $inspection = $this->metadataQaInspector->inspect($context->getFilePath(), $media);
        if ($inspection->hasIssues()) {
            $entry = $inspection->toIndexLogEntry();
            if ($entry !== null) {
                IndexLogHelper::appendEntry($media, $entry);
            }

            $this->qaReportCollector->record($media, $inspection);
            $context = $context->withQaFinding($inspection);
        }

        return $context->withMedia($media);
    }
}
