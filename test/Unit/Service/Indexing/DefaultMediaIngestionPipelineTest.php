<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Indexing;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\DefaultMediaIngestionPipeline;
use MagicSunday\Memories\Service\Metadata\MetadataExtractorInterface;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailServiceInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

use function file_put_contents;
use function filesize;
use function hash_file;
use function is_file;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class DefaultMediaIngestionPipelineTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    #[Test]
    public function processSkipsExistingMediaWhenForceDisabled(): void
    {
        $path     = $this->createTempFile('jpg', 'existing');
        $checksum = (string) hash_file('sha256', $path);
        $media    = new Media($path, $checksum, (int) filesize($path));
        $output  = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $repository->expects(self::once())
            ->method('findOneBy')
            ->willReturn($media);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Media::class)
            ->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');

        $metadataExtractor = $this->createMock(MetadataExtractorInterface::class);
        $metadataExtractor->expects(self::never())->method('extract');

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $thumbnailService->expects(self::never())->method('generateAll');

        $pipeline = new DefaultMediaIngestionPipeline(
            $entityManager,
            $metadataExtractor,
            $thumbnailService,
        );

        $result = $pipeline->process($path, false, false, false, false, $output);

        self::assertNull($result);
        self::assertStringContainsString('Übersprungen', $output->fetch());
    }

    #[Test]
    public function processPersistsAndFlushesOnFinalize(): void
    {
        $path   = $this->createTempFile('jpg', 'content');
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $repository->expects(self::once())
            ->method('findOneBy')
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Media::class)
            ->willReturn($repository);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::never())->method('clear');

        $metadataExtractor = $this->createMock(MetadataExtractorInterface::class);
        $metadataExtractor->expects(self::once())
            ->method('extract')
            ->willReturnCallback(static fn (string $file, Media $media): Media => $media);

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $thumbnailService->expects(self::never())->method('generateAll');

        $pipeline = new DefaultMediaIngestionPipeline(
            $entityManager,
            $metadataExtractor,
            $thumbnailService,
        );

        $result = $pipeline->process($path, false, false, false, false, $output);
        $pipeline->finalize(false);

        self::assertInstanceOf(Media::class, $result);
    }

    #[Test]
    public function processHonoursStrictMimeValidation(): void
    {
        $path   = $this->createTempFile('jpg', 'plain text');
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');
        $entityManager->expects(self::never())->method('persist');

        $metadataExtractor = $this->createMock(MetadataExtractorInterface::class);
        $metadataExtractor->expects(self::never())->method('extract');

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $thumbnailService->expects(self::never())->method('generateAll');

        $pipeline = new DefaultMediaIngestionPipeline(
            $entityManager,
            $metadataExtractor,
            $thumbnailService,
            ['jpg'],
            [],
        );

        $result = $pipeline->process($path, false, false, false, true, $output);

        self::assertNull($result);
    }

    private function createTempFile(string $extension, string $content): string
    {
        $path = sys_get_temp_dir() . '/memories-pipeline-' . uniqid('', true) . '.' . $extension;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
