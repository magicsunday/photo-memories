<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\Service\Indexing;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Contract\TimezoneResolverInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionStageInterface;
use MagicSunday\Memories\Service\Indexing\DefaultMediaIngestionPipeline;
use MagicSunday\Memories\Service\Indexing\Stage\TimeStage;
use MagicSunday\Memories\Service\Metadata\DaypartEnricher;
use MagicSunday\Memories\Service\Metadata\MetadataQaInspector;
use MagicSunday\Memories\Service\Metadata\MetadataQaReportCollector;
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;
use MagicSunday\Memories\Service\Metadata\SolarEnricher;
use MagicSunday\Memories\Service\Metadata\Support\CaptureTimeResolver;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;

use function str_repeat;

final class MetadataPipelineQaIntegrationTest extends TestCase
{
    #[Test]
    public function collectsQaFindingsDuringPipelineRun(): void
    {
        $timezoneResolver = new class implements TimezoneResolverInterface {
            public function resolveMediaTimezone(Media $media, DateTimeImmutable $takenAt, array $home): DateTimeZone
            {
                return new DateTimeZone('UTC');
            }

            public function resolveSummaryTimezone(array $summary, array $home): DateTimeZone
            {
                return new DateTimeZone('UTC');
            }

            public function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int
            {
                return null;
            }

            public function determineLocalTimezoneIdentifier(array $identifierVotes, array $home, ?int $offset): string
            {
                return 'UTC';
            }
        };

        $captureResolver = new CaptureTimeResolver($timezoneResolver);
        $qaCollector     = new MetadataQaReportCollector();

        $pipeline = new DefaultMediaIngestionPipeline([
            new SeedMediaStage(),
            new TimeStage(
                new NullExtractor(),
                new NullExtractor(),
                new NullExtractor(),
                new NullExtractor(),
                new MetadataQaInspector(new DaypartEnricher($captureResolver), new SolarEnricher($captureResolver)),
                $qaCollector,
            ),
        ], ['mp4']);

        $output = new BufferedOutput();
        $result = $pipeline->process('/fixtures/qa.jpg', false, false, false, false, $output);

        self::assertInstanceOf(Media::class, $result);

        $report = $qaCollector->buildReport();
        self::assertSame(1, $report->getFeatureCounts()['daypart'] ?? 0);
        self::assertSame(1, $report->getFeatureCounts()['isGoldenHour'] ?? 0);
        self::assertSame(1, $report->getFeatureCounts()['timezoneOffsetMin'] ?? 0);
        self::assertSame(['/fixtures/qa.jpg'], $report->getExamples()['daypart'] ?? []);

        $qaCollector->render($output);
        self::assertStringContainsString('QA-Bericht', $output->fetch());
    }
}

final class SeedMediaStage implements MediaIngestionStageInterface
{
    public function process(MediaIngestionContext $context): MediaIngestionContext
    {
        $media = new Media($context->getFilePath(), str_repeat('a', 64), 2048);
        $media->setTakenAt(new DateTimeImmutable('2024-06-01T10:00:00+00:00'));
        $media->setGpsLat(52.5);
        $media->setGpsLon(13.4);

        return $context->withMedia($media);
    }
}

final class NullExtractor implements SingleMetadataExtractorInterface
{
    public function supports(string $filepath, Media $media): bool
    {
        return true;
    }

    public function extract(string $filepath, Media $media): Media
    {
        return $media;
    }
}
