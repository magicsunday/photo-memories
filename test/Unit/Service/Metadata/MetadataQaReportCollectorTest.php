<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\MetadataQaInspectionResult;
use MagicSunday\Memories\Service\Metadata\MetadataQaReportCollector;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;

final class MetadataQaReportCollectorTest extends TestCase
{
    #[Test]
    public function aggregatesFindingsAndSuggestions(): void
    {
        $collector = new MetadataQaReportCollector(2);

        $media = $this->makeMedia(
            id: 1,
            path: '/fixtures/qa.jpg',
            takenAt: '2024-06-01T10:00:00+00:00',
        );
        $media->setGpsLat(52.5);
        $media->setGpsLon(13.4);

        $collector->record(
            $media,
            MetadataQaInspectionResult::withIssues(
                ['daypart', 'isGoldenHour', 'tzConfidence'],
                ['TimeNormalizer-Konfiguration prüfen', 'Zeitzonenquellen priorisieren'],
            ),
        );

        $report = $collector->buildReport();
        self::assertSame(
            [
                'daypart' => 1,
                'isGoldenHour' => 1,
                'tzConfidence' => 1,
            ],
            $report->getFeatureCounts(),
        );
        self::assertSame(
            [
                'TimeNormalizer-Konfiguration prüfen' => 1,
                'Zeitzonenquellen priorisieren' => 1,
            ],
            $report->getSuggestionCounts(),
        );
        self::assertSame(['/fixtures/qa.jpg'], $report->getExamples()['daypart'] ?? []);
    }

    #[Test]
    public function rendersConsoleReport(): void
    {
        $collector = new MetadataQaReportCollector(1);
        $media     = $this->makeMedia(
            id: 2,
            path: '/fixtures/no-timezone.jpg',
            takenAt: '2024-07-02T15:00:00+00:00',
        );

        $collector->record(
            $media,
            MetadataQaInspectionResult::withIssues(['timezoneOffsetMin'], ['TimeNormalizer-Konfiguration prüfen']),
        );

        $output = new BufferedOutput();
        $collector->render($output);

        $rendered = $output->fetch();
        self::assertStringContainsString('QA-Bericht', $rendered);
        self::assertStringContainsString('timezoneOffsetMin', $rendered);
        self::assertStringContainsString('Empfohlene Maßnahmen', $rendered);
    }
}
