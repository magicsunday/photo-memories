<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Metadata\BurstIndexExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class BurstIndexExtractorTest extends TestCase
{
    #[Test]
    public function usesSubSecondComponentAndLinksLivePair(): void
    {
        $media = $this->makeMedia(1, 'movie.mov', configure: static function (Media $media): void {
            $media->setMime('video/quicktime');
            $media->setBurstUuid('burst-uuid');
            $media->setSubSecOriginal(42);
            $media->setLivePairChecksum('pair-checksum');
        });

        $counterpart = $this->makeMedia(2, 'photo.heic', configure: static function (Media $media): void {
            $media->setMime('image/heic');
        });

        $repository = $this->createMock(MediaRepository::class);
        $repository
            ->expects(self::once())
            ->method('findLivePairCandidate')
            ->with('pair-checksum', $media->getPath())
            ->willReturn($counterpart);

        $extractor = new BurstIndexExtractor($repository);

        self::assertTrue($extractor->supports($media->getPath(), $media));

        $result = $extractor->extract($media->getPath(), $media);

        self::assertSame(42, $result->getBurstIndex());
        self::assertSame($counterpart, $result->getLivePairMedia());
        self::assertSame($result, $counterpart->getLivePairMedia());
    }

    #[Test]
    public function derivesBurstIndexFromFilenameWhenAvailable(): void
    {
        $media = $this->makeMedia(3, 'IMG_1001-BURST-0003.JPG', configure: static function (Media $media): void {
            $media->setMime('image/jpeg');
            $media->setBurstUuid('burst-uuid');
        });

        $repository = $this->createMock(MediaRepository::class);
        $repository->expects(self::never())->method('findLivePairCandidate');

        $extractor = new BurstIndexExtractor($repository);

        $result = $extractor->extract($media->getPath(), $media);

        self::assertSame(3, $result->getBurstIndex());
    }
}
