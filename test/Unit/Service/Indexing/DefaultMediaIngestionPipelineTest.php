<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Indexing;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Repository\MediaDuplicateRepository;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Hash\Contract\FastHashGeneratorInterface;
use MagicSunday\Memories\Service\Indexing\DefaultMediaIngestionPipeline;
use MagicSunday\Memories\Service\Indexing\Stage\BurstLiveStage;
use MagicSunday\Memories\Service\Indexing\Stage\ContentKindStage;
use MagicSunday\Memories\Service\Indexing\Stage\DuplicateHandlingStage;
use MagicSunday\Memories\Service\Indexing\Stage\FacesStage;
use MagicSunday\Memories\Service\Indexing\Stage\GeoStage;
use MagicSunday\Memories\Service\Indexing\Stage\HashStage;
use MagicSunday\Memories\Service\Indexing\Stage\NearDuplicateStage;
use MagicSunday\Memories\Service\Indexing\Stage\MetadataStage;
use MagicSunday\Memories\Service\Indexing\Stage\MimeDetectionStage;
use MagicSunday\Memories\Service\Indexing\Stage\PersistenceBatchStage;
use MagicSunday\Memories\Service\Indexing\Stage\QualityStage;
use MagicSunday\Memories\Service\Indexing\Stage\SceneStage;
use MagicSunday\Memories\Service\Indexing\Stage\ThumbnailGenerationStage;
use MagicSunday\Memories\Service\Indexing\Stage\TimeStage;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;
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
        $media->setFeatureVersion(MetadataFeatureVersion::PIPELINE_VERSION);
        $media->setIndexedAt(new DateTimeImmutable('-1 minute'));
        $output   = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $fastHash = 'feedfacecafe1234';

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
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $entityManager->expects(self::never())->method('clear');

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $thumbnailService->expects(self::never())->method('generateAll');

        $extractors = $this->createExtractorMap();
        $this->expectNoExtractorInteractions($extractors);

        $fastHashGenerator = $this->createMock(FastHashGeneratorInterface::class);
        $fastHashGenerator->expects(self::once())
            ->method('hash')
            ->with($path)
            ->willReturn($fastHash);

        $pipeline = $this->createPipeline(
            $entityManager,
            $thumbnailService,
            $extractors,
            ['jpg'],
            [],
            $fastHashGenerator
        );

        $result = $pipeline->process($path, false, false, false, false, $output);

        self::assertNull($result);
        self::assertStringContainsString('Übersprungen', $output->fetch());
        self::assertSame($fastHash, $media->getFastChecksumXxhash64());
    }

    #[Test]
    public function processPersistsMediaAndRunsExtractorsInOrder(): void
    {
        $path     = $this->createTempFile('jpg', 'content');
        $checksum = (string) hash_file('sha256', $path);
        $output   = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $fastHash = '1122334455667788';

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
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Media::class));
        $entityManager->expects(self::once())
            ->method('flush');
        $entityManager->expects(self::never())
            ->method('clear');

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $thumbnailService->expects(self::never())->method('generateAll');

        $extractors = $this->createExtractorMap();
        $callLog    = [];
        $this->configureExtractorOrderExpectations($extractors, $callLog);

        $fastHashGenerator = $this->createMock(FastHashGeneratorInterface::class);
        $fastHashGenerator->expects(self::once())
            ->method('hash')
            ->with($path)
            ->willReturn($fastHash);

        $pipeline = $this->createPipeline(
            $entityManager,
            $thumbnailService,
            $extractors,
            ['jpg'],
            [],
            $fastHashGenerator
        );

        $result = $pipeline->process($path, true, false, false, false, $output);
        $pipeline->finalize(false);

        self::assertInstanceOf(Media::class, $result);
        self::assertSame($fastHash, $result->getFastChecksumXxhash64());
        self::assertSame($this->expectedExtractorOrder(), $callLog);
    }

    #[Test]
    public function processReindexesOutdatedMediaWithoutForce(): void
    {
        $path     = $this->createTempFile('jpg', 'outdated');
        $checksum = (string) hash_file('sha256', $path);
        $output   = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $fastHash = 'cafebabefeed1234';

        $existing = new Media($path, $checksum, (int) filesize($path));
        $existing->setFeatureVersion(MetadataFeatureVersion::PIPELINE_VERSION - 1);
        $existing->setIndexedAt(new DateTimeImmutable('-1 hour'));

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['fastChecksumXxhash64' => $fastHash])
            ->willReturn($existing);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Media::class)
            ->willReturn($repository);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with($existing);
        $entityManager->expects(self::once())
            ->method('flush');
        $entityManager->expects(self::never())
            ->method('clear');

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $thumbnailService->expects(self::never())->method('generateAll');

        $extractors = $this->createExtractorMap();
        $callLog    = [];
        $this->configureExtractorOrderExpectations($extractors, $callLog);

        $fastHashGenerator = $this->createMock(FastHashGeneratorInterface::class);
        $fastHashGenerator->expects(self::once())
            ->method('hash')
            ->with($path)
            ->willReturn($fastHash);

        $pipeline = $this->createPipeline(
            $entityManager,
            $thumbnailService,
            $extractors,
            ['jpg'],
            [],
            $fastHashGenerator
        );

        $result = $pipeline->process($path, false, false, false, false, $output);
        $pipeline->finalize(false);

        self::assertSame($existing, $result);
        self::assertSame($this->expectedExtractorOrder(), $callLog);
    }

    #[Test]
    public function processHonoursStrictMimeValidation(): void
    {
        $path   = $this->createTempFile('jpg', 'plain text');
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');
        $entityManager->expects(self::never())->method('persist');

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $thumbnailService->expects(self::never())->method('generateAll');

        $extractors = $this->createExtractorMap();
        $this->expectNoExtractorInteractions($extractors);

        $fastHashGenerator = $this->createMock(FastHashGeneratorInterface::class);
        $fastHashGenerator->expects(self::never())->method('hash');

        $pipeline = $this->createPipeline(
            $entityManager,
            $thumbnailService,
            $extractors,
            ['jpg'],
            [],
            $fastHashGenerator
        );

        $result = $pipeline->process($path, false, false, false, true, $output);

        self::assertNull($result);
    }

    /**
     * @param array<string, array<string, SingleMetadataExtractorInterface>> $extractors
     */
    private function expectNoExtractorInteractions(array $extractors): void
    {
        foreach ($extractors as $group) {
            foreach ($group as $mock) {
                $mock->expects(self::never())->method('supports');
                $mock->expects(self::never())->method('extract');
            }
        }
    }

    /**
     * @param array<string, array<string, SingleMetadataExtractorInterface>> $extractors
     * @param list<string>                                                   $callLog
     */
    private function configureExtractorOrderExpectations(array $extractors, array &$callLog): void
    {
        foreach ($this->expectedExtractorGroups() as $group => $names) {
            foreach ($names as $name) {
                $identifier = $group . '.' . $name;
                $extractor  = $extractors[$group][$name];

                $extractor->expects(self::once())
                    ->method('supports')
                    ->willReturnCallback(static function (string $file, Media $media) use (&$callLog, $identifier): bool {
                        $callLog[] = $identifier . '.supports';

                        return true;
                    });
                $extractor->expects(self::once())
                    ->method('extract')
                    ->willReturnCallback(static function (string $file, Media $media) use (&$callLog, $identifier): Media {
                        $callLog[] = $identifier . '.extract';

                        return $media;
                    });
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function expectedExtractorGroups(): array
    {
        return [
            'metadata' => ['exif', 'xmp', 'fileStat', 'filenameKeyword', 'appleHeuristics', 'ffprobe'],
            'time'     => ['normalizer', 'calendar', 'daypart', 'solar'],
            'geo'      => ['feature'],
            'quality'  => ['vision'],
            'content'  => ['classifier'],
            'hash'     => ['perceptual'],
            'burst'    => ['detector', 'livePair', 'index'],
            'faces'    => ['detector'],
            'scene'    => ['clip'],
        ];
    }

    /**
     * @return list<string>
     */
    private function expectedExtractorOrder(): array
    {
        $order = [];
        foreach ($this->expectedExtractorGroups() as $group => $names) {
            foreach ($names as $name) {
                $order[] = $group . '.' . $name . '.supports';
                $order[] = $group . '.' . $name . '.extract';
            }
        }

        return $order;
    }

    /**
     * @return array<string, array<string, SingleMetadataExtractorInterface>>
     */
    private function createExtractorMap(): array
    {
        return [
            'metadata' => [
                'exif'             => $this->createMock(SingleMetadataExtractorInterface::class),
                'xmp'              => $this->createMock(SingleMetadataExtractorInterface::class),
                'fileStat'         => $this->createMock(SingleMetadataExtractorInterface::class),
                'filenameKeyword'  => $this->createMock(SingleMetadataExtractorInterface::class),
                'appleHeuristics'  => $this->createMock(SingleMetadataExtractorInterface::class),
                'ffprobe'          => $this->createMock(SingleMetadataExtractorInterface::class),
            ],
            'time' => [
                'normalizer' => $this->createMock(SingleMetadataExtractorInterface::class),
                'calendar'   => $this->createMock(SingleMetadataExtractorInterface::class),
                'daypart'    => $this->createMock(SingleMetadataExtractorInterface::class),
                'solar'      => $this->createMock(SingleMetadataExtractorInterface::class),
            ],
            'geo' => [
                'feature' => $this->createMock(SingleMetadataExtractorInterface::class),
            ],
            'quality' => [
                'vision' => $this->createMock(SingleMetadataExtractorInterface::class),
            ],
            'content' => [
                'classifier' => $this->createMock(SingleMetadataExtractorInterface::class),
            ],
            'hash' => [
                'perceptual' => $this->createMock(SingleMetadataExtractorInterface::class),
            ],
            'burst' => [
                'detector' => $this->createMock(SingleMetadataExtractorInterface::class),
                'livePair' => $this->createMock(SingleMetadataExtractorInterface::class),
                'index'    => $this->createMock(SingleMetadataExtractorInterface::class),
            ],
            'faces' => [
                'detector' => $this->createMock(SingleMetadataExtractorInterface::class),
            ],
            'scene' => [
                'clip' => $this->createMock(SingleMetadataExtractorInterface::class),
            ],
        ];
    }

    /**
     * @param list<string>|null $imageExtensions
     * @param list<string>|null $videoExtensions
     */
    private function createPipeline(
        EntityManagerInterface $entityManager,
        ThumbnailServiceInterface $thumbnailService,
        array $extractors,
        ?array $imageExtensions,
        ?array $videoExtensions,
        ?FastHashGeneratorInterface $fastHashGenerator = null,
        ?MediaRepository $mediaRepository = null,
        ?MediaDuplicateRepository $duplicateRepository = null,
    ): DefaultMediaIngestionPipeline {
        $fastHashGenerator ??= $this->createMock(FastHashGeneratorInterface::class);
        if ($mediaRepository === null) {
            $mediaRepository = $this->createMock(MediaRepository::class);
            $mediaRepository->expects(self::never())->method('findNearestByPhash');
        }

        if ($duplicateRepository === null) {
            $duplicateRepository = $this->createMock(MediaDuplicateRepository::class);
            $duplicateRepository->expects(self::never())->method('recordDistance');
        }

        return new DefaultMediaIngestionPipeline([
            new MimeDetectionStage($imageExtensions, $videoExtensions),
            new DuplicateHandlingStage($entityManager, $fastHashGenerator),
            new MetadataStage(
                $extractors['metadata']['exif'],
                $extractors['metadata']['xmp'],
                $extractors['metadata']['fileStat'],
                $extractors['metadata']['filenameKeyword'],
                $extractors['metadata']['appleHeuristics'],
                $extractors['metadata']['ffprobe'],
            ),
            new TimeStage(
                $extractors['time']['normalizer'],
                $extractors['time']['calendar'],
                $extractors['time']['daypart'],
                $extractors['time']['solar'],
            ),
            new GeoStage($extractors['geo']['feature']),
            new QualityStage($extractors['quality']['vision']),
            new ContentKindStage($extractors['content']['classifier']),
            new HashStage($extractors['hash']['perceptual']),
            new NearDuplicateStage($mediaRepository, $duplicateRepository),
            new BurstLiveStage(
                $extractors['burst']['detector'],
                $extractors['burst']['livePair'],
                $extractors['burst']['index'],
            ),
            new FacesStage($extractors['faces']['detector']),
            new SceneStage($extractors['scene']['clip']),
            new ThumbnailGenerationStage($thumbnailService),
            new PersistenceBatchStage($entityManager, 10),
        ]);
    }

    private function createTempFile(string $extension, string $content): string
    {
        $path = sys_get_temp_dir() . '/memories-pipeline-' . uniqid('', true) . '.' . $extension;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
