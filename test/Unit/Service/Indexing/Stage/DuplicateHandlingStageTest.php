<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Indexing\Stage;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Stage\DuplicateHandlingStage;
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

        $stage = new DuplicateHandlingStage($entityManager);
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
    }

    #[Test]
    public function processCreatesMediaWhenNoExistingEntryFound(): void
    {
        $filepath = $this->createTempFile('jpg', 'fresh');

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

        $stage = new DuplicateHandlingStage($entityManager);
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
        self::assertSame('image/jpeg', $result->getMedia()?->getMime());
    }

    private function createTempFile(string $extension, string $content): string
    {
        $path = sys_get_temp_dir() . '/memories-stage-' . uniqid('', true) . '.' . $extension;
        file_put_contents($path, $content);
        $this->tempFile = $path;

        return $path;
    }
}
