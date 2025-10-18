<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Command;

use DateTimeImmutable;
use MagicSunday\Memories\Command\MemoriesCurateCommand;
use MagicSunday\Memories\Service\Clusterer\ClusterJobOptions;
use MagicSunday\Memories\Service\Clusterer\ClusterJobResult;
use MagicSunday\Memories\Service\Clusterer\ClusterJobTelemetry;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterJobRunnerInterface;
use MagicSunday\Memories\Service\Feed\Contract\FeedExportServiceInterface;
use MagicSunday\Memories\Service\Feed\FeedExportRequest;
use MagicSunday\Memories\Service\Feed\FeedExportResult;
use MagicSunday\Memories\Service\Feed\FeedExportStage;
use MagicSunday\Memories\Service\Indexing\MediaFileLocatorInterface;
use MagicSunday\Memories\Service\Indexing\MediaIngestionPipelineInterface;
use MagicSunday\Memories\Service\Metadata\MetadataQaReportCollector;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;


final class MemoriesCurateCommandTest extends TestCase
{
    #[Test]
    public function itRunsTheFullPipeline(): void
    {
        $locator = $this->createMock(MediaFileLocatorInterface::class);
        $locator->expects(self::once())
            ->method('locate')
            ->with('/data/media', null, true)
            ->willReturn(new \ArrayIterator(['/data/media/a.jpg', '/data/media/b.jpg']));

        $pipeline = $this->createMock(MediaIngestionPipelineInterface::class);
        $pipeline->expects(self::exactly(2))
            ->method('process')
            ->with(
                self::callback(static fn (string $path): bool => str_contains($path, '/data/media/')),
                true,
                false,
                false,
                false,
                self::isInstanceOf(\Symfony\Component\Console\Output\OutputInterface::class),
            );
        $pipeline->expects(self::once())->method('finalize')->with(false);

        $qaCollector = $this->createMock(MetadataQaReportCollector::class);
        $qaCollector->expects(self::atLeastOnce())->method('reset');
        $qaCollector->expects(self::once())
            ->method('render')
            ->with(self::isInstanceOf(\Symfony\Component\Console\Output\OutputInterface::class));

        $telemetry = ClusterJobTelemetry::fromStageCounts(2, 2);
        $jobResult = new ClusterJobResult(5, 4, 3, 2, 2, 0, false, $telemetry);

        $runner = $this->createMock(ClusterJobRunnerInterface::class);
        $runner->expects(self::once())
            ->method('run')
            ->with(
                self::callback(function (ClusterJobOptions $options): bool {
                    self::assertFalse($options->isDryRun());
                    self::assertNull($options->getLimit());
                    self::assertEquals(new DateTimeImmutable('2024-01-01'), $options->getSince());
                    self::assertEquals(new DateTimeImmutable('2024-12-31'), $options->getUntil());
                    self::assertSame(['travel_and_places'], $options->getAllowedGroups());

                    return true;
                }),
                self::anything(),
            )
            ->willReturn($jobResult);

        $exportService = $this->createMock(FeedExportServiceInterface::class);
        $exportService->expects(self::once())
            ->method('export')
            ->with(
                self::callback(function (FeedExportRequest $request): bool {
                    self::assertSame(5000, $request->getLimitClusters());

                    return true;
                }),
                self::isInstanceOf(\Symfony\Component\Console\Style\SymfonyStyle::class),
            )
            ->willReturn(new FeedExportResult(
                'out',
                'images',
                'index.html',
                5,
                1,
                3,
                FeedExportStage::Curated,
                [
                    FeedExportStage::Raw->value     => 5,
                    FeedExportStage::Merged->value  => 4,
                    FeedExportStage::Curated->value => 3,
                ],
            ));

        $command = new MemoriesCurateCommand(
            $locator,
            $pipeline,
            $qaCollector,
            $runner,
            $exportService,
            ['vacation' => 'travel_and_places', 'year_in_review' => 'time_and_basics'],
            '/data/media',
        );

        $tester = new CommandTester($command);

        $status = $tester->execute([
            '--types' => ['travel_and_places'],
            '--since' => '2024-01-01',
            '--until' => '2024-12-31',
            '--reindex' => 'force',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Cluster konsolidiert', $tester->getDisplay());
        self::assertStringContainsString('HTML erzeugt', $tester->getDisplay());
    }

    #[Test]
    public function itSkipsIndexingWhenRequested(): void
    {
        $locator   = $this->createMock(MediaFileLocatorInterface::class);
        $pipeline  = $this->createMock(MediaIngestionPipelineInterface::class);
        $qa        = $this->createMock(MetadataQaReportCollector::class);

        $runner = $this->createMock(ClusterJobRunnerInterface::class);
        $runner->expects(self::once())
            ->method('run')
            ->willReturn(new ClusterJobResult(0, 0, 0, 0, 0, 0, false));

        $export = $this->createMock(FeedExportServiceInterface::class);
        $export->expects(self::once())->method('export')->willReturn(new FeedExportResult(
            'out',
            'images',
            null,
            0,
            0,
            0,
            FeedExportStage::Curated,
            [
                FeedExportStage::Raw->value     => 0,
                FeedExportStage::Merged->value  => 0,
                FeedExportStage::Curated->value => 0,
            ],
        ));

        $command = new MemoriesCurateCommand(
            $locator,
            $pipeline,
            $qa,
            $runner,
            $export,
            ['vacation' => 'travel_and_places'],
            '/data/media',
        );

        $pipeline->expects(self::never())->method('process');
        $pipeline->expects(self::never())->method('finalize');

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--reindex' => 'skip',
        ]);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function itRejectsInvalidType(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--types' => ['unknown'],
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Unbekannter Erinnerungstyp', $tester->getDisplay());
    }

    #[Test]
    public function itRejectsInvalidDates(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--since' => 'invalid',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Ungültiges Datum', $tester->getDisplay());
    }

    #[Test]
    public function itRejectsChronologicallyInconsistentDates(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--since' => '2024-12-31',
            '--until' => '2024-01-01',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Startdatum darf nicht nach dem Enddatum', $tester->getDisplay());
    }

    #[Test]
    public function itSkipsFeedExportDuringDryRun(): void
    {
        $locator = $this->createMock(MediaFileLocatorInterface::class);
        $locator->method('locate')->willReturn(new \ArrayIterator(['/data/media/a.jpg']));

        $pipeline = $this->createMock(MediaIngestionPipelineInterface::class);
        $pipeline->expects(self::once())->method('process');
        $pipeline->expects(self::once())->method('finalize')->with(true);

        $qaCollector = $this->createMock(MetadataQaReportCollector::class);
        $qaCollector->expects(self::atLeastOnce())->method('reset');

        $runner = $this->createMock(ClusterJobRunnerInterface::class);
        $runner->expects(self::once())
            ->method('run')
            ->willReturn(new ClusterJobResult(1, 1, 1, 1, 0, 0, true));

        $export = $this->createMock(FeedExportServiceInterface::class);
        $export->expects(self::never())->method('export');

        $command = new MemoriesCurateCommand(
            $locator,
            $pipeline,
            $qaCollector,
            $runner,
            $export,
            ['vacation' => 'travel_and_places'],
            '/data/media',
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Dry-Run: Feed-Export übersprungen', $tester->getDisplay());
    }

    private function createCommand(): MemoriesCurateCommand
    {
        $locator = $this->createMock(MediaFileLocatorInterface::class);
        $pipeline = $this->createMock(MediaIngestionPipelineInterface::class);
        $qa       = $this->createMock(MetadataQaReportCollector::class);
        $runner   = $this->createMock(ClusterJobRunnerInterface::class);
        $runner->method('run')->willReturn(new ClusterJobResult(0, 0, 0, 0, 0, 0, false));
        $export   = $this->createMock(FeedExportServiceInterface::class);
        $export->method('export')->willReturn(new FeedExportResult(
            'out',
            'images',
            null,
            0,
            0,
            0,
            FeedExportStage::Curated,
            [
                FeedExportStage::Raw->value     => 0,
                FeedExportStage::Merged->value  => 0,
                FeedExportStage::Curated->value => 0,
            ],
        ));

        return new MemoriesCurateCommand(
            $locator,
            $pipeline,
            $qa,
            $runner,
            $export,
            ['vacation' => 'travel_and_places'],
            '/data/media',
        );
    }
}

