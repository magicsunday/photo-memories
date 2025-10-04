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
use MagicSunday\Memories\Service\Metadata\MetadataExtractorInterface;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
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

        $extractor = $this->createMock(MetadataExtractorInterface::class);
        $extractor->expects(self::once())
            ->method('extract')
            ->with('/library/image.jpg', $media)
            ->willReturnCallback(static fn (string $file, Media $entity): Media => $entity);

        $stage   = new MetadataExtractionStage($extractor);
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
        self::assertSame(MetadataFeatureVersion::CURRENT, $media->getFeatureVersion());
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

        $extractor = $this->createMock(MetadataExtractorInterface::class);
        $extractor->expects(self::once())
            ->method('extract')
            ->willThrowException(new RuntimeException('boom'));

        $stage  = new MetadataExtractionStage($extractor);
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
        self::assertSame(MetadataFeatureVersion::CURRENT, $media->getFeatureVersion());
        self::assertInstanceOf(DateTimeImmutable::class, $media->getIndexedAt());

        $log = $media->getIndexLog();
        self::assertIsString($log);
        self::assertStringContainsString('RuntimeException', $log);
        self::assertStringContainsString('boom', $log);

        $buffer = $output->fetch();
        self::assertStringContainsString('Metadata extraction failed', $buffer);
    }
}
