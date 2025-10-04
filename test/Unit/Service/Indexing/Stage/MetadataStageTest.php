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
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Stage\MetadataStage;
use MagicSunday\Memories\Service\Metadata\AppleHeuristicsExtractor;
use MagicSunday\Memories\Service\Metadata\ExifMetadataExtractor;
use MagicSunday\Memories\Service\Metadata\FileStatMetadataExtractor;
use MagicSunday\Memories\Service\Metadata\FilenameKeywordExtractor;
use MagicSunday\Memories\Service\Metadata\FfprobeMetadataExtractor;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;
use MagicSunday\Memories\Service\Metadata\XmpIptcExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

final class MetadataStageTest extends TestCase
{
    #[Test]
    public function processUpdatesMetadataOnSuccess(): void
    {
        $media = $this->makeMedia(
            id: 42,
            path: '/library/image.jpg',
        );
        $media->setIndexLog('stale entry');

        $exif = $this->createMock(ExifMetadataExtractor::class);
        $exif->expects(self::once())
            ->method('supports')
            ->with('/library/image.jpg', $media)
            ->willReturn(true);
        $exif->expects(self::once())
            ->method('extract')
            ->with('/library/image.jpg', $media)
            ->willReturnCallback(static fn (string $file, Media $entity): Media => $entity);

        $xmp              = $this->createRejectingExtractor(XmpIptcExtractor::class);
        $fileStat         = $this->createRejectingExtractor(FileStatMetadataExtractor::class);
        $filenameKeyword  = $this->createRejectingExtractor(FilenameKeywordExtractor::class);
        $appleHeuristics  = $this->createRejectingExtractor(AppleHeuristicsExtractor::class);
        $ffprobe          = $this->createRejectingExtractor(FfprobeMetadataExtractor::class);

        $stage  = new MetadataStage($exif, $xmp, $fileStat, $filenameKeyword, $appleHeuristics, $ffprobe);
        $output = new BufferedOutput();

        $context = MediaIngestionContext::create(
            '/library/image.jpg',
            false,
            false,
            false,
            false,
            $output,
        )->withMedia($media);

        $result = $stage->process($context);

        self::assertSame($media, $result->getMedia());
        self::assertSame(MetadataFeatureVersion::PIPELINE_VERSION, $media->getFeatureVersion());
        self::assertInstanceOf(DateTimeImmutable::class, $media->getIndexedAt());
        self::assertNull($media->getIndexLog());
    }

    #[Test]
    public function processLogsFailureWhenExtractorThrows(): void
    {
        $media = $this->makeMedia(
            id: 7,
            path: '/library/broken.jpg',
        );

        $extractor = $this->createMock(ExifMetadataExtractor::class);
        $extractor->expects(self::once())
            ->method('supports')
            ->with('/library/broken.jpg', $media)
            ->willReturn(true);
        $extractor->expects(self::once())
            ->method('extract')
            ->willThrowException(new RuntimeException('boom'));

        $xmp              = $this->createUnusedExtractor(XmpIptcExtractor::class);
        $fileStat         = $this->createUnusedExtractor(FileStatMetadataExtractor::class);
        $filenameKeyword  = $this->createUnusedExtractor(FilenameKeywordExtractor::class);
        $appleHeuristics  = $this->createUnusedExtractor(AppleHeuristicsExtractor::class);
        $ffprobe          = $this->createUnusedExtractor(FfprobeMetadataExtractor::class);

        $stage  = new MetadataStage($extractor, $xmp, $fileStat, $filenameKeyword, $appleHeuristics, $ffprobe);
        $output = new BufferedOutput();

        $context = MediaIngestionContext::create(
            '/library/broken.jpg',
            false,
            false,
            false,
            false,
            $output,
        )->withMedia($media);

        $result = $stage->process($context);

        self::assertSame($media, $result->getMedia());
        self::assertSame(MetadataFeatureVersion::PIPELINE_VERSION, $media->getFeatureVersion());
        self::assertInstanceOf(DateTimeImmutable::class, $media->getIndexedAt());

        $log = $media->getIndexLog();
        self::assertIsString($log);
        self::assertSame('RuntimeException: boom', $log);

        $buffer = $output->fetch();
        self::assertStringContainsString('Metadata extraction failed for /library/broken.jpg: boom', $buffer);
    }

    #[Test]
    public function processSkipsWhenFeatureVersionMatchesAndNotForced(): void
    {
        $media = $this->makeMedia(
            id: 17,
            path: '/library/already-indexed.jpg',
        );
        $indexedAt = new DateTimeImmutable('-1 day');
        $media->setIndexedAt($indexedAt);
        $media->setFeatureVersion(MetadataFeatureVersion::PIPELINE_VERSION);
        $media->setIndexLog('keep this');

        $extractors = [
            $this->createUnusedExtractor(ExifMetadataExtractor::class),
            $this->createUnusedExtractor(XmpIptcExtractor::class),
            $this->createUnusedExtractor(FileStatMetadataExtractor::class),
            $this->createUnusedExtractor(FilenameKeywordExtractor::class),
            $this->createUnusedExtractor(AppleHeuristicsExtractor::class),
            $this->createUnusedExtractor(FfprobeMetadataExtractor::class),
        ];

        $stage  = new MetadataStage(...$extractors);
        $output = new BufferedOutput();

        $context = MediaIngestionContext::create(
            '/library/already-indexed.jpg',
            false,
            false,
            false,
            false,
            $output,
        )->withMedia($media);

        $result = $stage->process($context);

        self::assertSame($media, $result->getMedia());
        self::assertSame($indexedAt, $media->getIndexedAt());
        self::assertSame('keep this', $media->getIndexLog());
    }

    #[Test]
    public function processRunsExtractorsWhenForceFlagSet(): void
    {
        $media = $this->makeMedia(
            id: 21,
            path: '/library/force.jpg',
        );
        $previousIndexedAt = new DateTimeImmutable('-2 days');
        $media->setIndexedAt($previousIndexedAt);
        $media->setFeatureVersion(MetadataFeatureVersion::PIPELINE_VERSION);
        $media->setIndexLog('stale warning');

        $extractor = $this->createMock(ExifMetadataExtractor::class);
        $extractor->expects(self::once())
            ->method('supports')
            ->with('/library/force.jpg', $media)
            ->willReturn(true);
        $extractor->expects(self::once())
            ->method('extract')
            ->willReturnCallback(static fn (string $file, Media $entity): Media => $entity);

        $xmp              = $this->createRejectingExtractor(XmpIptcExtractor::class);
        $fileStat         = $this->createRejectingExtractor(FileStatMetadataExtractor::class);
        $filenameKeyword  = $this->createRejectingExtractor(FilenameKeywordExtractor::class);
        $appleHeuristics  = $this->createRejectingExtractor(AppleHeuristicsExtractor::class);
        $ffprobe          = $this->createRejectingExtractor(FfprobeMetadataExtractor::class);

        $stage  = new MetadataStage($extractor, $xmp, $fileStat, $filenameKeyword, $appleHeuristics, $ffprobe);
        $output = new BufferedOutput();

        $context = MediaIngestionContext::create(
            '/library/force.jpg',
            true,
            false,
            false,
            false,
            $output,
        )->withMedia($media);

        $result = $stage->process($context);

        self::assertSame($media, $result->getMedia());
        self::assertSame(MetadataFeatureVersion::PIPELINE_VERSION, $media->getFeatureVersion());
        $indexedAt = $media->getIndexedAt();
        self::assertInstanceOf(DateTimeImmutable::class, $indexedAt);
        self::assertNotSame($previousIndexedAt, $indexedAt);
        self::assertNull($media->getIndexLog());
    }

    #[Test]
    public function processNormalizesEmptyLogToNull(): void
    {
        $media = $this->makeMedia(
            id: 32,
            path: '/library/warn.jpg',
        );

        $extractor = $this->createMock(ExifMetadataExtractor::class);
        $extractor->expects(self::once())
            ->method('supports')
            ->with('/library/warn.jpg', $media)
            ->willReturn(true);
        $extractor->expects(self::once())
            ->method('extract')
            ->willReturnCallback(static function (string $file, Media $entity): Media {
                $entity->setIndexLog('');

                return $entity;
            });

        $xmp              = $this->createRejectingExtractor(XmpIptcExtractor::class);
        $fileStat         = $this->createRejectingExtractor(FileStatMetadataExtractor::class);
        $filenameKeyword  = $this->createRejectingExtractor(FilenameKeywordExtractor::class);
        $appleHeuristics  = $this->createRejectingExtractor(AppleHeuristicsExtractor::class);
        $ffprobe          = $this->createRejectingExtractor(FfprobeMetadataExtractor::class);

        $stage  = new MetadataStage($extractor, $xmp, $fileStat, $filenameKeyword, $appleHeuristics, $ffprobe);
        $output = new BufferedOutput();

        $context = MediaIngestionContext::create(
            '/library/warn.jpg',
            false,
            false,
            false,
            false,
            $output,
        )->withMedia($media);

        $result = $stage->process($context);

        self::assertSame($media, $result->getMedia());
        self::assertSame(MetadataFeatureVersion::PIPELINE_VERSION, $media->getFeatureVersion());
        self::assertInstanceOf(DateTimeImmutable::class, $media->getIndexedAt());
        self::assertNull($media->getIndexLog());
    }

    /**
     * @template T of SingleMetadataExtractorInterface
     *
     * @param class-string<T> $class
     *
     * @return T&SingleMetadataExtractorInterface
     */
    private function createRejectingExtractor(string $class): SingleMetadataExtractorInterface
    {
        $mock = $this->createMock($class);
        $mock->expects(self::once())
            ->method('supports')
            ->willReturn(false);
        $mock->expects(self::never())
            ->method('extract');

        return $mock;
    }

    /**
     * @template T of SingleMetadataExtractorInterface
     *
     * @param class-string<T> $class
     *
     * @return T&SingleMetadataExtractorInterface
     */
    private function createUnusedExtractor(string $class): SingleMetadataExtractorInterface
    {
        $mock = $this->createMock($class);
        $mock->expects(self::never())
            ->method('supports');
        $mock->expects(self::never())
            ->method('extract');

        return $mock;
    }
}
