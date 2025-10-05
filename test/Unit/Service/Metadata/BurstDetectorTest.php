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
use MagicSunday\Memories\Service\Metadata\BurstDetector;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class BurstDetectorTest extends TestCase
{
    #[Test]
    public function skipsMediaWithExistingBurstInformation(): void
    {
        $media = $this->makeMedia(1, 'photo.heic', '2024-02-02T12:00:00+00:00', configure: static function (Media $media): void {
            $media->setMime('image/heic');
            $media->setBurstUuid('apple-burst');
        });

        $repository = $this->createMock(MediaRepository::class);
        $repository->expects(self::never())->method('findNearestByPhash');

        $detector = new BurstDetector($repository);

        $result = $detector->extract($media->getPath(), $media);

        self::assertSame('apple-burst', $result->getBurstUuid());
    }

    #[Test]
    public function assignsBurstUuidAndIndexHeuristically(): void
    {
        $baseTime = '2024-02-02T12:00:00+00:00';

        $media = $this->makeMedia(2, 'burst-1.heic', $baseTime, configure: static function (Media $media): void {
            $media->setMime('image/heic');
            $media->setPhash('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
            $media->setSharpness(0.20);
        });

        $neighbor = $this->makeMedia(3, 'burst-2.heic', '2024-02-02T12:00:02+00:00', configure: static function (Media $media): void {
            $media->setMime('image/heic');
            $media->setPhash('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
            $media->setSharpness(0.80);
        });

        $far = $this->makeMedia(4, 'other.heic', '2024-02-02T12:00:10+00:00', configure: static function (Media $media): void {
            $media->setMime('image/heic');
            $media->setBurstUuid('keep-existing');
        });

        $repository = $this->createMock(MediaRepository::class);
        $repository
            ->expects(self::once())
            ->method('findNearestByPhash')
            ->with('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 8, 16)
            ->willReturn([
                ['media' => $neighbor, 'distance' => 3],
                ['media' => $far, 'distance' => 1],
            ]);
        $repository->expects(self::never())->method('findBurstMembers');

        $detector = new BurstDetector($repository);

        $detector->extract($media->getPath(), $media);

        $burstId = $media->getBurstUuid();
        self::assertNotNull($burstId);
        self::assertStringStartsWith('heuristic-burst-', $burstId);
        self::assertSame($burstId, $neighbor->getBurstUuid());
        self::assertSame(0, $media->getBurstIndex());
        self::assertSame(1, $neighbor->getBurstIndex());
        self::assertSame('keep-existing', $far->getBurstUuid());
        self::assertFalse($media->isBurstRepresentative());
        self::assertTrue($neighbor->isBurstRepresentative());
        self::assertNull($far->isBurstRepresentative());
    }

    #[Test]
    public function reprocessesExistingBurstAndKeepsRepresentativeStable(): void
    {
        $media = $this->makeMedia(5, 'existing-1.heic', '2024-02-02T12:00:00+00:00', configure: static function (Media $media): void {
            $media->setMime('image/heic');
            $media->setBurstUuid('existing-burst');
            $media->setBurstIndex(5);
            $media->setSharpness(0.30);
        });

        $sibling = $this->makeMedia(6, 'existing-2.heic', '2024-02-02T12:00:01+00:00', configure: static function (Media $media): void {
            $media->setMime('image/heic');
            $media->setBurstUuid('existing-burst');
            $media->setBurstIndex(1);
            $media->setSharpness(0.85);
        });

        $repository = $this->createMock(MediaRepository::class);
        $repository->expects(self::never())->method('findNearestByPhash');
        $repository
            ->expects(self::once())
            ->method('findBurstMembers')
            ->with('existing-burst', $media->getPath())
            ->willReturn([$sibling]);

        $detector = new BurstDetector($repository);

        $detector->extract($media->getPath(), $media);

        self::assertFalse($media->isBurstRepresentative());
        self::assertTrue($sibling->isBurstRepresentative());

        $detector->extract($media->getPath(), $media);

        self::assertFalse($media->isBurstRepresentative());
        self::assertTrue($sibling->isBurstRepresentative());
    }
}
