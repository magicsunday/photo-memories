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
use MagicSunday\Memories\Service\Metadata\LivePairLinker;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class LivePairLinkerTest extends TestCase
{
    #[Test]
    public function linksCounterpartViaAppleChecksum(): void
    {
        $photo = $this->makeMedia(10, 'IMG_0001.HEIC', '2024-01-01T10:00:00+00:00', configure: static function (Media $media): void {
            $media->setMime('image/heic');
            $media->setLivePairChecksum('apple-checksum');
        });

        $video = $this->makeMedia(11, 'IMG_0001.MOV', '2024-01-01T10:00:00+00:00', configure: static function (Media $media): void {
            $media->setMime('video/quicktime');
            $media->setIsVideo(true);
        });

        $repository = $this->createMock(MediaRepository::class);
        $repository
            ->expects(self::once())
            ->method('findLivePairCandidate')
            ->with('apple-checksum', $photo->getPath())
            ->willReturn($video);
        $repository
            ->expects(self::never())
            ->method('findNearestByPhash');

        $linker = new LivePairLinker($repository);

        $linker->extract($photo->getPath(), $photo);

        self::assertSame($video, $photo->getLivePairMedia());
        self::assertSame($photo, $video->getLivePairMedia());
        self::assertSame('apple-checksum', $photo->getLivePairChecksum());
        self::assertSame('apple-checksum', $video->getLivePairChecksum());
    }

    #[Test]
    public function pairsPhotoAndVideoHeuristically(): void
    {
        $photo = $this->makeMedia(20, 'LivePhoto.JPG', '2024-01-02T08:15:30+00:00', configure: static function (Media $media): void {
            $media->setMime('image/jpeg');
            $media->setPhash('cccccccccccccccccccccccccccccccc');
        });

        $video = $this->makeMedia(21, 'LivePhoto.MOV', '2024-01-02T08:15:31+00:00', configure: static function (Media $media): void {
            $media->setMime('video/quicktime');
            $media->setIsVideo(true);
            $media->setPhash('dddddddddddddddddddddddddddddddd');
        });

        $repository = $this->createMock(MediaRepository::class);
        $repository->expects(self::never())->method('findLivePairCandidate');
        $repository
            ->expects(self::once())
            ->method('findNearestByPhash')
            ->with('cccccccccccccccccccccccccccccccc', 8, 16)
            ->willReturn([
                ['media' => $video, 'distance' => 4],
            ]);

        $linker = new LivePairLinker($repository);

        $linker->extract($photo->getPath(), $photo);

        $expectedChecksum = sha1('heuristic-live|' . $photo->getChecksum() . '|' . $video->getChecksum());

        self::assertSame($video, $photo->getLivePairMedia());
        self::assertSame($photo, $video->getLivePairMedia());
        self::assertSame($expectedChecksum, $photo->getLivePairChecksum());
        self::assertSame($expectedChecksum, $video->getLivePairChecksum());
    }
}
