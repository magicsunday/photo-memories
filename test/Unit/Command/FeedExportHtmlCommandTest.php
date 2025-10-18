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
use MagicSunday\Memories\Command\FeedExportHtmlCommand;
use MagicSunday\Memories\Service\Feed\Contract\FeedExportServiceInterface;
use MagicSunday\Memories\Service\Feed\FeedExportRequest;
use MagicSunday\Memories\Service\Feed\FeedExportResult;
use MagicSunday\Memories\Service\Feed\FeedExportStage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

use function sys_get_temp_dir;
use function uniqid;

final class FeedExportHtmlCommandTest extends TestCase
{
    #[Test]
    public function executeDelegatesToExportService(): void
    {
        $service = $this->createMock(FeedExportServiceInterface::class);

        $result = new FeedExportResult(
            outputDirectory: '/tmp/feed-out',
            imageDirectory: '/tmp/feed-out/images',
            indexFilePath: '/tmp/feed-out/index.html',
            copiedFileCount: 4,
            skippedNoThumbnailCount: 2,
            cardCount: 3,
            defaultStage: FeedExportStage::Curated,
            stageCardCounts: [
                FeedExportStage::Raw->value     => 5,
                FeedExportStage::Merged->value  => 4,
                FeedExportStage::Curated->value => 3,
            ],
        );

        $capturedRequest = null;

        $service->expects(self::once())
            ->method('export')
            ->willReturnCallback(function (FeedExportRequest $request, SymfonyStyle $io) use (&$capturedRequest, $result) {
                $capturedRequest = $request;

                return $result;
            });

        $command = new FeedExportHtmlCommand($service);
        $tester  = new CommandTester($command);

        $outputDir = sys_get_temp_dir() . '/memories-export-' . uniqid('', true);

        $tester->execute([
            '--limit-clusters'  => '25',
            '--max-items'       => '5',
            '--images-per-item' => '2',
            '--thumb-width'     => '320',
            '--symlink'         => true,
            'out-dir'           => $outputDir,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertNotNull($capturedRequest);
        self::assertSame(25, $capturedRequest->getLimitClusters());
        self::assertSame(5, $capturedRequest->getMaxItems());
        self::assertSame(2, $capturedRequest->getImagesPerItem());
        self::assertSame(320, $capturedRequest->getThumbnailWidth());
        self::assertTrue($capturedRequest->useSymlinks());
        self::assertSame($outputDir, $capturedRequest->getBaseOutputDirectory());
        self::assertInstanceOf(DateTimeImmutable::class, $capturedRequest->getTimestamp());
        self::assertSame(FeedExportStage::Curated, $capturedRequest->getStage());

        $display = $tester->getDisplay();
        self::assertStringContainsString('HTML erzeugt: /tmp/feed-out/index.html', $display);
        self::assertStringContainsString('Bilder: 4 kopiert/verlinkt, 2 übersprungen', $display);
    }

    #[Test]
    public function executeReturnsFailureWhenServiceThrows(): void
    {
        $service = $this->createMock(FeedExportServiceInterface::class);
        $service->expects(self::once())
            ->method('export')
            ->willThrowException(new RuntimeException('Fehler beim Export.'));

        $command = new FeedExportHtmlCommand($service);
        $tester  = new CommandTester($command);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Fehler beim Export.', $tester->getDisplay());
    }
}
