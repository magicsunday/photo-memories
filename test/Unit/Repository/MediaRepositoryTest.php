<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function str_contains;

final class MediaRepositoryTest extends TestCase
{
    #[Test]
    public function findNearestByPhashReturnsMediaWithDistances(): void
    {
        $mediaA = $this->makeMedia(10, 'a.jpg');
        $mediaB = $this->makeMedia(11, 'b.jpg');

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'BIT_COUNT')),
                [
                    'phashHex'   => 'abcdef',
                    'maxHamming' => 3,
                    'limit'      => 5,
                ],
                [
                    'phashHex'   => ParameterType::STRING,
                    'maxHamming' => ParameterType::INTEGER,
                    'limit'      => ParameterType::INTEGER,
                ]
            )
            ->willReturn([
                ['id' => '10', 'hamming' => '1'],
                ['id' => '11', 'hamming' => '2'],
            ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em
            ->expects(self::exactly(2))
            ->method('find')
            ->willReturnMap([
                [Media::class, 10, null, null, $mediaA],
                [Media::class, 11, null, null, $mediaB],
            ]);

        $repository = new MediaRepository($em);

        $result = $repository->findNearestByPhash('ABCDEF', 3, 5);

        self::assertCount(2, $result);
        self::assertSame($mediaA, $result[0]['media']);
        self::assertSame(1, $result[0]['distance']);
        self::assertSame($mediaB, $result[1]['media']);
        self::assertSame(2, $result[1]['distance']);
    }

    #[Test]
    public function findLivePairCandidateReturnsFirstNonHiddenMatch(): void
    {
        $existing = $this->makeMedia(21, 'existing.mov', configure: static function (Media $media): void {
            $media->setNoShow(true);
        });
        $candidate = $this->makeMedia(22, 'candidate.heic');

        $repoMock = $this->createMock(EntityRepository::class);
        $repoMock
            ->expects(self::once())
            ->method('findBy')
            ->with(['livePairChecksum' => 'checksum'], ['id' => 'ASC'], 8)
            ->willReturn([$existing, $candidate]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createMock(Connection::class));
        $em->method('getRepository')->with(Media::class)->willReturn($repoMock);

        $repository = new MediaRepository($em);

        $result = $repository->findLivePairCandidate(' checksum ', ' candidate-video.mov ');

        self::assertSame($candidate, $result);
    }
}
