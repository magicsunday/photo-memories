<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Feed;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Cluster;
use MagicSunday\Memories\Feed\MemoryFeedItem;
use MagicSunday\Memories\Repository\ClusterRepository;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidatorInterface;
use MagicSunday\Memories\Service\Feed\FeedBuilderInterface;
use MagicSunday\Memories\Service\Feed\FeedExportRequest;
use MagicSunday\Memories\Service\Feed\FeedExportResult;
use MagicSunday\Memories\Service\Feed\FeedExportStage;
use MagicSunday\Memories\Service\Feed\HtmlFeedExportService;
use MagicSunday\Memories\Service\Feed\HtmlFeedRenderer;
use MagicSunday\Memories\Service\Feed\ThumbnailPathResolver;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Style\SymfonyStyle;

use function basename;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class HtmlFeedExportServiceTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    #[Test]
    public function exportReturnsEarlyWhenNoClustersWereFound(): void
    {
        $baseDir = $this->createTempDir();

        $request = new FeedExportRequest(
            limitClusters: 10,
            maxItems: 5,
            imagesPerItem: 3,
            thumbnailWidth: 256,
            useSymlinks: false,
            baseOutputDirectory: $baseDir,
            timestamp: new DateTimeImmutable('2024-02-03 10:00:00'),
            stage: FeedExportStage::Curated,
        );

        $clusterRepository = $this->createMock(ClusterRepository::class);
        $clusterRepository->expects(self::once())
            ->method('findLatest')
            ->with(10)
            ->willReturn([]);

        $mapper = new ClusterEntityToDraftMapper();

        $consolidator = $this->createMock(ClusterConsolidatorInterface::class);
        $consolidator->expects(self::never())->method('consolidate');

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::never())->method('build');

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository->expects(self::never())->method('findByIds');

        $renderer          = new HtmlFeedRenderer();
        $thumbnailResolver = new ThumbnailPathResolver();

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::once())
            ->method('title')
            ->with('📰 HTML-Vorschau des Rückblick-Feeds');
        $io->expects(self::once())
            ->method('warning')
            ->with('Keine Cluster in der Datenbank gefunden.');

        $service = new HtmlFeedExportService(
            $clusterRepository,
            $feedBuilder,
            $consolidator,
            $mapper,
            $mediaRepository,
            $renderer,
            $thumbnailResolver,
        );

        $result = $service->export($request, $io);

        self::assertInstanceOf(FeedExportResult::class, $result);
        self::assertFalse($result->hasIndexFile());
        self::assertSame($request->resolveOutputDirectory(), $result->getOutputDirectory());
        self::assertSame($request->resolveImageDirectory(), $result->getImageDirectory());
        self::assertSame(0, $result->getCopiedFileCount());
        self::assertSame(0, $result->getSkippedNoThumbnailCount());
        self::assertSame(0, $result->getCardCount());
        self::assertSame(FeedExportStage::Curated, $result->getDefaultStage());
        self::assertSame(
            [
                FeedExportStage::Raw->value     => 0,
                FeedExportStage::Merged->value  => 0,
                FeedExportStage::Curated->value => 0,
            ],
            $result->getStageCardCounts(),
        );

        self::assertTrue(is_dir($result->getOutputDirectory()));
        self::assertTrue(is_dir($result->getImageDirectory()));
    }

    #[Test]
    public function exportCopiesThumbnailsAndCreatesHtml(): void
    {
        $baseDir     = $this->createTempDir();
        $thumbSource = $baseDir . '/source-thumb.jpg';
        file_put_contents($thumbSource, 'thumbnail');

        $request = new FeedExportRequest(
            limitClusters: 5,
            maxItems: 10,
            imagesPerItem: 4,
            thumbnailWidth: 512,
            useSymlinks: false,
            baseOutputDirectory: $baseDir,
            timestamp: new DateTimeImmutable('2024-02-03 10:30:00'),
            stage: FeedExportStage::Curated,
        );

        $cluster = new Cluster(
            'story',
            'algo',
            ['group' => 'familie'],
            ['lat'   => 0.0, 'lon' => 0.0],
            [1, 2],
        );

        $feedItem = new MemoryFeedItem(
            'algo',
            'Titel',
            'Untertitel',
            1,
            [1, 2],
            0.9,
            [
                'group'      => 'familie',
                'scene_tags' => [
                    ['label' => 'Familie', 'score' => 0.92],
                    ['label' => 'Outdoor', 'score' => 0.81],
                ],
            ]
        );

        $clusterRepository = $this->createMock(ClusterRepository::class);
        $clusterRepository->expects(self::exactly(2))
            ->method('findLatest')
            ->with(5)
            ->willReturn([$cluster]);

        $mapper = new ClusterEntityToDraftMapper();

        $consolidator = $this->createMock(ClusterConsolidatorInterface::class);
        $consolidator->expects(self::exactly(2))
            ->method('consolidate')
            ->willReturnCallback(static function (array $drafts): array {
                self::assertCount(1, $drafts);
                self::assertInstanceOf(ClusterDraft::class, $drafts[0]);

                return $drafts;
            });

        $feedBuilder = $this->createMock(FeedBuilderInterface::class);
        $feedBuilder->expects(self::exactly(2))
            ->method('build')
            ->willReturnCallback(static function (array $drafts) use ($feedItem): array {
                self::assertCount(1, $drafts);
                self::assertInstanceOf(ClusterDraft::class, $drafts[0]);

                return [$feedItem];
            });

        $mediaOne = $this->makeMedia(1, $baseDir . '/media-1.jpg');
        $mediaOne->setThumbnails([512 => $thumbSource]);
        $mediaOne->setSceneTags([
            ['label' => 'Familie', 'score' => 0.92],
            ['label' => 'Outdoor', 'score' => 0.81],
        ]);

        $mediaTwo = $this->makeMedia(2, $baseDir . '/media-2.jpg');
        $mediaTwo->setThumbnails(null);

        $mediaRepository = $this->createMock(MediaRepository::class);
        $mediaRepository->expects(self::atLeastOnce())
            ->method('findByIds')
            ->with([1, 2], false)
            ->willReturn([$mediaOne, $mediaTwo]);

        $thumbnailResolver = new ThumbnailPathResolver();
        $renderer          = new HtmlFeedRenderer();

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(self::exactly(2))
            ->method('title')
            ->with('📰 HTML-Vorschau des Rückblick-Feeds');
        $io->expects(self::never())->method('warning');

        $service = new HtmlFeedExportService(
            $clusterRepository,
            $feedBuilder,
            $consolidator,
            $mapper,
            $mediaRepository,
            $renderer,
            $thumbnailResolver,
        );

        $result = $service->export($request, $io);

        self::assertTrue($result->hasIndexFile());
        self::assertSame(1, $result->getCopiedFileCount());
        self::assertSame(3, $result->getSkippedNoThumbnailCount());
        self::assertSame(1, $result->getCardCount());
        self::assertSame(FeedExportStage::Curated, $result->getDefaultStage());
        self::assertSame(
            [
                FeedExportStage::Raw->value     => 1,
                FeedExportStage::Merged->value  => 1,
                FeedExportStage::Curated->value => 1,
            ],
            $result->getStageCardCounts(),
        );

        $imagePath = $result->getImageDirectory() . '/m1_source-thumb.jpg';
        self::assertTrue(file_exists($imagePath));
        self::assertSame('m1_source-thumb.jpg', basename($imagePath));
        self::assertNotNull($result->getIndexFilePath());
        $indexHtml = file_get_contents($result->getIndexFilePath());
        self::assertStringContainsString('Titel', $indexHtml);
        self::assertStringContainsString('Untertitel', $indexHtml);
        self::assertStringContainsString('Familie (0,92)', $indexHtml);
        self::assertStringContainsString('Outdoor (0,81)', $indexHtml);
        self::assertStringContainsString('Szene: Familie (0,92', $indexHtml);
        self::assertStringContainsString('Roh-Cluster', $indexHtml);
        self::assertStringContainsString('Konsolidierte Drafts', $indexHtml);
        self::assertStringContainsString('Kuratiertes Feed', $indexHtml);
        self::assertStringContainsString('Feed-Ranking', $indexHtml);
        self::assertStringContainsString('stage-nav__item is-active"><a href="#stage-curated"', $indexHtml);

        $requestRaw = new FeedExportRequest(
            limitClusters: 5,
            maxItems: 10,
            imagesPerItem: 4,
            thumbnailWidth: 512,
            useSymlinks: false,
            baseOutputDirectory: $baseDir,
            timestamp: new DateTimeImmutable('2024-02-03 11:00:00'),
            stage: FeedExportStage::Raw,
        );

        $resultRaw = $service->export($requestRaw, $io);

        self::assertSame(FeedExportStage::Raw, $resultRaw->getDefaultStage());
        self::assertSame(
            [
                FeedExportStage::Raw->value     => 1,
                FeedExportStage::Merged->value  => 1,
                FeedExportStage::Curated->value => 1,
            ],
            $resultRaw->getStageCardCounts(),
        );

        self::assertNotNull($resultRaw->getIndexFilePath());
        $indexHtmlRaw = file_get_contents($resultRaw->getIndexFilePath());
        self::assertStringContainsString('stage-nav__item is-active"><a href="#stage-raw"', $indexHtmlRaw);
    }

    private function createTempDir(): string
    {
        $dir = sprintf('%s/memories-feed-%s', sys_get_temp_dir(), uniqid('', true));

        if (!is_dir($dir) && !mkdir($dir) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create temporary directory: ' . $dir);
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof SplFileInfo && $fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());

                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($dir);
    }
}
