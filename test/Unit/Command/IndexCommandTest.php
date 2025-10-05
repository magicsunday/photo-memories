<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Command;

use MagicSunday\Memories\Command\IndexCommand;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\MediaFileLocatorInterface;
use MagicSunday\Memories\Service\Indexing\MediaIngestionPipelineInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class IndexCommandTest extends TestCase
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
    public function executeFailsWhenPathIsMissing(): void
    {
        $locator = $this->createMock(MediaFileLocatorInterface::class);
        $locator->expects(self::never())->method('locate');

        $pipeline = $this->createMock(MediaIngestionPipelineInterface::class);
        $pipeline->expects(self::never())->method('process');
        $pipeline->expects(self::never())->method('finalize');

        $command = new IndexCommand($locator, $pipeline, sys_get_temp_dir());
        $tester  = new CommandTester($command);

        $status = $tester->execute([
            'path' => '/does/not/exist',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Pfad existiert nicht', $tester->getDisplay());
    }

    #[Test]
    public function executeDelegatesToLocatorAndPipeline(): void
    {
        $baseDir = $this->createTempDir();
        $fileOne = $baseDir . '/one.jpg';
        $fileTwo = $baseDir . '/two.jpg';
        file_put_contents($fileOne, 'one');
        file_put_contents($fileTwo, 'two');

        $locator = $this->createMock(MediaFileLocatorInterface::class);
        $locator->expects(self::once())
            ->method('locate')
            ->with($baseDir, null)
            ->willReturn([$fileOne, $fileTwo]);

        $processedMedia = new Media($fileOne, 'checksum', 3);

        $pipeline  = $this->createMock(MediaIngestionPipelineInterface::class);
        $callCount = 0;
        $pipeline->expects(self::exactly(2))
            ->method('process')
            ->willReturnCallback(function (string $file, bool $force, bool $dryRun, bool $withThumbnails, bool $strictMime, OutputInterface $output) use (&$callCount, $fileOne, $fileTwo, $processedMedia): ?Media {
                ++$callCount;

                self::assertFalse($force);
                self::assertFalse($dryRun);
                self::assertFalse($withThumbnails);
                self::assertFalse($strictMime);
                self::assertInstanceOf(OutputInterface::class, $output);

                if ($callCount === 1) {
                    self::assertSame($fileOne, $file);

                    return $processedMedia;
                }

                if ($callCount === 2) {
                    self::assertSame($fileTwo, $file);

                    return null;
                }

                throw new RuntimeException('Unexpected pipeline invocation.');
            });
        $pipeline->expects(self::once())
            ->method('finalize')
            ->with(false);

        $command = new IndexCommand($locator, $pipeline, $baseDir);
        $tester  = new CommandTester($command);

        $tester->execute([
            'path'          => $baseDir,
            '--no-progress' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Insgesamt verarbeitete Dateien: 1', $tester->getDisplay());
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/memories-command-' . uniqid('', true);

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
