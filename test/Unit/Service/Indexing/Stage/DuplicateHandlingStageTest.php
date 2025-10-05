<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Indexing\Stage;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Hash\Contract\FastHashGeneratorInterface;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Stage\DuplicateHandlingStage;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

use function file_put_contents;
use function hash_file;
use function is_file;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class DuplicateHandlingStageTest extends TestCase
{
    private ?string $tempFile = null;

    protected function tearDown(): void
    {
        if ($this->tempFile !== null && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }

        $this->tempFile = null;

        parent::tearDown();
    }

    #[Test]
    public function processSkipsWhenExistingMediaFoundAndForceDisabled(): void
    {
        $filepath = $this->createTempFile('jpg', 'existing');
        $checksum = (string) hash_file('sha256', $filepath);
        $media    = new Media($filepath, $checksum, 1);

        $media->setFeatureVersion(MetadataFeatureVersion::PIPELINE_VERSION);
        $media->setIndexedAt(new DateTimeImmutable());

        $fastHash = '4c3d2b1a0f0e0d0c';

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['fastChecksumXxhash64' => $fastHash])
            ->willReturn($media);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Media::class)
            ->willReturn($repository);

        $fastHashGenerator = $this->createMock(FastHashGeneratorInterface::class);
        $fastHashGenerator->expects(self::once())
            ->method('hash')
            ->with($filepath)
            ->willReturn($fastHash);

        $stage   = new DuplicateHandlingStage($entityManager, $fastHashGenerator);
        $context = MediaIngestionContext::create(
            $filepath,
            false,
            false,
            false,
            false,
            new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE)
        )->withDetectedMime('image/jpeg');

        $result = $stage->process($context);

        self::assertTrue($result->isSkipped());
        self::assertNull($result->getMedia());
        self::assertSame($fastHash, $media->getFastChecksumXxhash64());
    }

    #[Test]
    public function processReprocessesExistingMediaWhenFeatureVersionOutdated(): void
    {
        $filepath = $this->createTempFile('jpg', 'existing-outdated');
        $checksum = (string) hash_file('sha256', $filepath);
        $media    = new Media($filepath, $checksum, 1);

        $media->setFeatureVersion(MetadataFeatureVersion::PIPELINE_VERSION - 1);
        $media->setIndexedAt(new DateTimeImmutable('-1 day'));

        $fastHash = 'feedfacecafebeef';

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['fastChecksumXxhash64' => $fastHash])
            ->willReturn($media);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Media::class)
            ->willReturn($repository);

        $fastHashGenerator = $this->createMock(FastHashGeneratorInterface::class);
        $fastHashGenerator->expects(self::once())
            ->method('hash')
            ->with($filepath)
            ->willReturn($fastHash);

        $stage   = new DuplicateHandlingStage($entityManager, $fastHashGenerator);
        $context = MediaIngestionContext::create(
            $filepath,
            false,
            false,
            false,
            false,
            new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE)
        );

        $result = $stage->process($context);

        self::assertFalse($result->isSkipped());
        self::assertSame($checksum, $result->getChecksum());
        self::assertSame($media, $result->getMedia());
        self::assertTrue($result->requiresReindex());
        self::assertSame($fastHash, $media->getFastChecksumXxhash64());
    }

    #[Test]
    public function processCreatesMediaWhenNoExistingEntryFound(): void
    {
        $filepath = $this->createTempFile('jpg', 'fresh');
        $checksum = (string) hash_file('sha256', $filepath);
        $fastHash = '1234567890abcdef';

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $repository->expects(self::exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($fastHash, $checksum): ?Media {
                static $call = 0;
                ++$call;

                if ($call === 1) {
                    self::assertSame(['fastChecksumXxhash64' => $fastHash], $criteria);

                    return null;
                }

                self::assertSame(['checksum' => $checksum], $criteria);

                return null;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Media::class)
            ->willReturn($repository);

        $fastHashGenerator = $this->createMock(FastHashGeneratorInterface::class);
        $fastHashGenerator->expects(self::once())
            ->method('hash')
            ->with($filepath)
            ->willReturn($fastHash);

        $stage   = new DuplicateHandlingStage($entityManager, $fastHashGenerator);
        $context = MediaIngestionContext::create(
            $filepath,
            false,
            false,
            false,
            false,
            new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE)
        )->withDetectedMime('image/jpeg');

        $result = $stage->process($context);

        self::assertFalse($result->isSkipped());
        self::assertNotNull($result->getChecksum());
        self::assertInstanceOf(Media::class, $result->getMedia());
        self::assertSame($checksum, $result->getChecksum());
        self::assertSame($fastHash, $result->getMedia()?->getFastChecksumXxhash64());
        self::assertSame('image/jpeg', $result->getMedia()?->getMime());
    }

    #[Test]
    public function processReusesExistingMediaWhenForceEnabled(): void
    {
        $filepath = $this->createTempFile('jpg', 'existing-force');
        $checksum = (string) hash_file('sha256', $filepath);
        $fastHash = 'abcdefabcdefabcd';
        $media    = new Media($filepath, $checksum, 1);

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['fastChecksumXxhash64' => $fastHash])
            ->willReturn($media);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Media::class)
            ->willReturn($repository);

        $fastHashGenerator = $this->createMock(FastHashGeneratorInterface::class);
        $fastHashGenerator->expects(self::once())
            ->method('hash')
            ->with($filepath)
            ->willReturn($fastHash);

        $stage   = new DuplicateHandlingStage($entityManager, $fastHashGenerator);
        $context = MediaIngestionContext::create(
            $filepath,
            true,
            false,
            false,
            false,
            new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE)
        );

        $result = $stage->process($context);

        self::assertFalse($result->isSkipped());
        self::assertSame($checksum, $result->getChecksum());
        self::assertSame($media, $result->getMedia());
        self::assertSame($fastHash, $media->getFastChecksumXxhash64());
    }

    #[Test]
    public function processAppliesDetectedFormatFlagsToMedia(): void
    {
        $filepath = $this->createTempFile('heic', 'format-flags');
        $checksum = (string) hash_file('sha256', $filepath);
        $fastHash = 'abcdefabcdefabcd';

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $repository->expects(self::exactly(2))
            ->method('findOneBy')
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Media::class)
            ->willReturn($repository);

        $fastHashGenerator = $this->createMock(FastHashGeneratorInterface::class);
        $fastHashGenerator->expects(self::once())
            ->method('hash')
            ->with($filepath)
            ->willReturn($fastHash);

        $stage   = new DuplicateHandlingStage($entityManager, $fastHashGenerator);
        $context = MediaIngestionContext::create(
            $filepath,
            false,
            false,
            false,
            false,
            new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE)
        )->withDetectedMime('image/heic', true, true, true);

        $result = $stage->process($context);

        $media = $result->getMedia();
        self::assertInstanceOf(Media::class, $media);
        self::assertTrue($media->isRaw());
        self::assertTrue($media->isHeic());
        self::assertTrue($media->isHevc());
        self::assertSame('image/heic', $media->getMime());
        self::assertSame($checksum, $media->getChecksum());
    }

    private function createTempFile(string $extension, string $content): string
    {
        $path = sys_get_temp_dir() . '/memories-stage-' . uniqid('', true) . '.' . $extension;
        file_put_contents($path, $content);
        $this->tempFile = $path;

        return $path;
    }
}
