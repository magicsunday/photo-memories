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
use MagicSunday\Memories\Service\Metadata\DaypartEnricher;
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;
use MagicSunday\Memories\Service\Metadata\SolarEnricher;
use MagicSunday\Memories\Service\Metadata\TimeNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TimeStage extends AbstractExtractorStage
{
    /**
     * @var iterable<SingleMetadataExtractorInterface>
     */
    private readonly iterable $extractors;

    public function __construct(
        #[Autowire(service: TimeNormalizer::class)]
        SingleMetadataExtractorInterface $normalizer,
        #[Autowire(service: CalendarFeatureEnricher::class)]
        SingleMetadataExtractorInterface $calendar,
        #[Autowire(service: DaypartEnricher::class)]
        SingleMetadataExtractorInterface $daypart,
        #[Autowire(service: SolarEnricher::class)]
        SingleMetadataExtractorInterface $solar,
    ) {
        $this->extractors = [
            $normalizer,
            $calendar,
            $daypart,
            $solar,
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
