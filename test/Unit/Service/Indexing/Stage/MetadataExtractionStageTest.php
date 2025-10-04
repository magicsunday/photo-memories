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
use MagicSunday\Memories\Service\Indexing\Stage\MetadataExtractionStage;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

final class MetadataExtractionStageTest extends TestCase
{
    #[Test]
    public function updatesIndexMetadataOnSuccess(): void
    {
        $media = $this->makeMedia(
            id: 42,
            path: '/library/image.jpg',
        );
        $media->setIndexLog('stale entry');

        $extractor = $this->createMock(SingleMetadataExtractorInterface::class);
        $extractor->expects(self::once())
            ->method('supports')
            ->with('/library/image.jpg', $media)
            ->willReturn(true);
        $extractor->expects(self::once())
            ->method('extract')
            ->with('/library/image.jpg', $media)
            ->willReturnCallback(static fn (string $file, Media $entity): Media => $entity);

        $stage   = new MetadataExtractionStage([$extractor]);
        $output  = new BufferedOutput();
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
    public function writesFailureLogWhenExtractionThrows(): void
    {
        $media = $this->makeMedia(
            id: 7,
            path: '/library/broken.jpg',
        );

        $extractor = $this->createMock(SingleMetadataExtractorInterface::class);
        $extractor->expects(self::once())
            ->method('supports')
            ->with('/library/broken.jpg', $media)
            ->willReturn(true);
        $extractor->expects(self::once())
            ->method('extract')
            ->willThrowException(new RuntimeException('boom'));

        $stage  = new MetadataExtractionStage([$extractor]);
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
        self::assertStringContainsString('RuntimeException', $log);
        self::assertStringContainsString('boom', $log);

        $buffer = $output->fetch();
        self::assertStringContainsString('Metadata extraction failed', $buffer);
    }

    #[Test]
    public function skipsExtractionWhenFeatureVersionMatchesAndNotForced(): void
    {
        $media = $this->makeMedia(
            id: 17,
            path: '/library/already-indexed.jpg',
        );
        $indexedAt = new DateTimeImmutable('-1 day');
        $media->setIndexedAt($indexedAt);
        $media->setFeatureVersion(MetadataFeatureVersion::PIPELINE_VERSION);
        $media->setIndexLog('keep this');

        $extractor = $this->createMock(SingleMetadataExtractorInterface::class);
        $extractor->expects(self::never())->method('supports');
        $extractor->expects(self::never())
            ->method('extract');

        $stage  = new MetadataExtractionStage([$extractor]);
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
    public function forcesExtractionWhenFlagIsSet(): void
    {
        $media = $this->makeMedia(
            id: 21,
            path: '/library/force.jpg',
        );
        $previousIndexedAt = new DateTimeImmutable('-2 days');
        $media->setIndexedAt($previousIndexedAt);
        $media->setFeatureVersion(MetadataFeatureVersion::PIPELINE_VERSION);
        $media->setIndexLog('stale warning');

        $extractor = $this->createMock(SingleMetadataExtractorInterface::class);
        $extractor->expects(self::once())
            ->method('supports')
            ->with('/library/force.jpg', $media)
            ->willReturn(true);
        $extractor->expects(self::once())
            ->method('extract')
            ->willReturnCallback(static function (string $file, Media $entity): Media {
                return $entity;
            });

        $stage  = new MetadataExtractionStage([$extractor]);
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
    public function keepsWarningLogOnSuccessfulExtraction(): void
    {
        $media = $this->makeMedia(
            id: 32,
            path: '/library/warn.jpg',
        );

        $extractor = $this->createMock(SingleMetadataExtractorInterface::class);
        $extractor->expects(self::once())
            ->method('supports')
            ->with('/library/warn.jpg', $media)
            ->willReturn(true);
        $extractor->expects(self::once())
            ->method('extract')
            ->willReturnCallback(static function (string $file, Media $entity): Media {
                $entity->setIndexLog('warning: exif missing');

                return $entity;
            });

        $stage  = new MetadataExtractionStage([$extractor]);
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
        self::assertSame('warning: exif missing', $media->getIndexLog());
    }
}
