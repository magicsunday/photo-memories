<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
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
                self::callback(static fn (string $sql): bool => str_contains($sql, 'phashPrefix = :phashPrefix')),
                [
                    'phashHex'    => 'abcdef0123456789',
                    'phashPrefix' => 'abcdef0123456789',
                    'maxHamming'  => 3,
                    'limit'       => 5,
                ],
                [
                    'phashHex'    => ParameterType::STRING,
                    'phashPrefix' => ParameterType::STRING,
                    'maxHamming'  => ParameterType::INTEGER,
                    'limit'       => ParameterType::INTEGER,
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

        $repository = new MediaRepository($em, 16);

        $result = $repository->findNearestByPhash('ABCDEF0123456789ABCDEF0123456789', 3, 5);

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

    #[Test]
    public function findIndexingCandidatesAppliesMetadataFiltersAndLimit(): void
    {
        $media = $this->makeMedia(30, 'candidate.jpg');

        $query = $this->createMock(AbstractQuery::class);
        $query
            ->expects(self::once())
            ->method('getResult')
            ->willReturn([$media]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())->method('select')->with('m')->willReturn($qb);
        $qb->expects(self::once())->method('from')->with(Media::class, 'm')->willReturn($qb);
        $qb->expects(self::once())->method('where')->with('m.noShow = false')->willReturn($qb);
        $qb->expects(self::once())->method('andWhere')->with('m.indexedAt IS NULL OR m.featureVersion < :pipelineVersion')->willReturn($qb);
        $qb->expects(self::once())->method('setParameter')->with('pipelineVersion', MetadataFeatureVersion::PIPELINE_VERSION)->willReturn($qb);
        $qb->expects(self::once())->method('orderBy')->with('m.id', 'ASC')->willReturn($qb);
        $qb->expects(self::once())->method('setMaxResults')->with(5)->willReturn($qb);
        $qb->expects(self::once())->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('createQueryBuilder')->willReturn($qb);

        $repository = new MediaRepository($em);

        $result = $repository->findIndexingCandidates(5);

        self::assertSame([$media], $result);
    }

    #[Test]
    public function findGoodCandidatesByDateRangeFiltersByWindowAndQuality(): void
    {
        $media = $this->makeMedia(42, 'range.jpg');

        $from = new DateTimeImmutable('2023-01-01T00:00:00+00:00');
        $to   = new DateTimeImmutable('2023-01-31T23:59:59+00:00');

        $query = $this->createMock(AbstractQuery::class);
        $query
            ->expects(self::once())
            ->method('getResult')
            ->willReturn([$media]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::once())->method('select')->with('m')->willReturn($qb);
        $qb->expects(self::once())->method('from')->with(Media::class, 'm')->willReturn($qb);
        $qb->expects(self::once())->method('where')->with('m.noShow = false')->willReturn($qb);
        $qb->expects(self::exactly(2))->method('andWhere')->withConsecutive(
            ['m.lowQuality = false'],
            ['m.takenAt BETWEEN :from AND :to'],
        )->willReturn($qb);
        $qb->expects(self::exactly(2))->method('setParameter')->withConsecutive(
            ['from', $from],
            ['to', $to],
        )->willReturn($qb);
        $qb->expects(self::once())->method('orderBy')->with('m.takenAt', 'ASC')->willReturn($qb);
        $qb->expects(self::once())->method('addOrderBy')->with('m.id', 'ASC')->willReturn($qb);
        $qb->expects(self::once())->method('setMaxResults')->with(1)->willReturn($qb);
        $qb->expects(self::once())->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('createQueryBuilder')->willReturn($qb);

        $repository = new MediaRepository($em);

        $result = $repository->findGoodCandidatesByDateRange($from, $to, 0);

        self::assertSame([$media], $result);
    }
}
