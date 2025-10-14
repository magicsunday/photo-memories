<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\Service\Clusterer;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Cluster;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\ClusterJobOptions;
use MagicSunday\Memories\Service\Clusterer\ClusterPersistenceService;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterBuildProgressCallbackInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidatorInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterMemberSelectionServiceInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\HybridClustererInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ProgressHandleInterface;
use MagicSunday\Memories\Service\Clusterer\DefaultClusterJobRunner;
use MagicSunday\Memories\Service\Clusterer\NullProgressReporter;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Feed\CoverPickerInterface;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class DefaultClusterJobRunnerTest extends TestCase
{
    #[Test]
    public function itKeepsPersistedClustersWhenAbortingAfterFirstStrategy(): void
    {
        $mediaOne = $this->makeMedia(1, '/one.jpg', new DateTimeImmutable('2024-01-10T08:00:00+00:00'));
        $mediaOne->setFeatureVersion(MetadataFeatureVersion::CURRENT);
        $mediaOne->setMime('image/jpeg');
        $mediaOne->setIsVideo(false);

        $mediaTwo = $this->makeMedia(2, '/two.jpg', new DateTimeImmutable('2024-01-10T09:00:00+00:00'));
        $mediaTwo->setFeatureVersion(MetadataFeatureVersion::CURRENT);
        $mediaTwo->setMime('image/jpeg');
        $mediaTwo->setIsVideo(false);

        $countQb    = $this->createQueryBuilderMock(['select', 'from', 'andWhere', 'setParameter', 'getQuery']);
        $countQuery = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $countQuery->expects(self::once())->method('getSingleScalarResult')->willReturn('2');
        $countQb->method('getQuery')->willReturn($countQuery);

        $listQb = $this->createQueryBuilderMock(['select', 'from', 'orderBy', 'addOrderBy', 'andWhere', 'setParameter', 'setMaxResults', 'getQuery']);
        $listQuery = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toIterable'])
            ->getMock();
        $listQuery->expects(self::once())->method('toIterable')->willReturn([$mediaOne, $mediaTwo]);
        $listQb->method('getQuery')->willReturn($listQuery);

        $readEntityManager = $this->createMock(EntityManagerInterface::class);
        $readEntityManager->expects(self::exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($countQb, $listQb);

        $drafts = [
            new ClusterDraft('algo-one', [], ['lat' => 1.0, 'lon' => 1.0], [1]),
            new ClusterDraft('algo-two', [], ['lat' => 2.0, 'lon' => 2.0], [2]),
        ];

        $clusterer    = new SequenceHybridClusterer($drafts);
        $consolidator = new PassthroughClusterConsolidator();

        $storedClusters  = [];
        $pendingClusters = [];
        $flushCalls      = 0;

        $clusterEntityManager = $this->createMock(EntityManagerInterface::class);
        $clusterEntityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$pendingClusters): void {
                if ($entity instanceof Cluster) {
                    $pendingClusters[] = $entity;
                }
            }
        );
        $clusterEntityManager->method('clear')->willReturnCallback(
            static function () use (&$pendingClusters): void {
                $pendingClusters = [];
            }
        );
        $clusterEntityManager->method('createQueryBuilder')->willReturnCallback(
            static function (): object {
                return new EmptyClusterQueryBuilder();
            }
        );
        $clusterEntityManager->method('flush')->willReturnCallback(
            static function () use (&$pendingClusters, &$storedClusters, &$flushCalls): void {
                ++$flushCalls;

                if ($flushCalls === 2) {
                    throw new RuntimeException('Simulated abort');
                }

                foreach ($pendingClusters as $entity) {
                    $storedClusters[] = $entity;
                }

                $pendingClusters = [];
            }
        );

        $mediaLookup   = new InMemoryMemberMediaLookup([$mediaOne, $mediaTwo]);
        $memberSelect  = new IdentityClusterMemberSelectionService();
        $coverPicker   = new FirstCoverPicker();
        $persistence   = new ClusterPersistenceService(
            em: $clusterEntityManager,
            mediaLookup: $mediaLookup,
            memberSelection: $memberSelect,
            coverPicker: $coverPicker,
            defaultBatchSize: 1,
            maxMembers: 50,
            fingerprintLookupBatchSize: 10,
        );

        $runner  = new DefaultClusterJobRunner($readEntityManager, $clusterer, $consolidator, $persistence);
        $options = new ClusterJobOptions(false, null, null, false);

        try {
            $runner->run($options, new NullProgressReporter());
            self::fail('Expected simulated abort to bubble up.');
        } catch (RuntimeException $exception) {
            self::assertSame('Simulated abort', $exception->getMessage());
        }

        self::assertCount(1, $storedClusters, 'Clusters persisted before abort should remain available.');
        self::assertSame('algo-one', $storedClusters[0]->getAlgorithm());
        self::assertSame(1, $storedClusters[0]->getMembersCount());
    }

    /**
     * @param list<string> $methods
     */
    private function createQueryBuilderMock(array $methods): QueryBuilder
    {
        $qb = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();

        foreach ($methods as $method) {
            if ($method === 'getQuery') {
                continue;
            }

            $qb->method($method)->willReturnSelf();
        }

        return $qb;
    }
}

/**
 * @implements HybridClustererInterface
 */
final class SequenceHybridClusterer implements HybridClustererInterface
{
    /**
     * @param list<ClusterDraft> $drafts
     */
    public function __construct(private readonly array $drafts)
    {
    }

    public function countStrategies(): int
    {
        return count($this->drafts);
    }

    public function build(
        array $items,
        ?callable $onStart,
        ?callable $onDone,
        ?callable $makeProgress = null,
        ?ClusterBuildProgressCallbackInterface $progressCallback = null,
    ): array {
        $total = max(1, count($this->drafts));
        $index = 0;

        foreach ($this->drafts as $draft) {
            ++$index;
            $strategy = $draft->getAlgorithm();

            if ($onStart !== null) {
                $onStart($strategy, $index, $total);
            }

            if ($makeProgress !== null) {
                $progressHandle = $makeProgress($strategy, $index, $total);
                if ($progressHandle instanceof ProgressHandleInterface) {
                    $progressHandle->setDetail('Persisted');
                    $progressHandle->setProgress(1);
                    $progressHandle->finish();
                }
            }

            if ($onDone !== null) {
                $onDone($strategy, $index, $total);
            }
        }

        return $this->drafts;
    }
}

final class PassthroughClusterConsolidator implements ClusterConsolidatorInterface
{
    public function consolidate(array $drafts, ?callable $progress = null): array
    {
        if ($progress !== null) {
            $progress(count($drafts), count($drafts), 'passthrough');
        }

        return $drafts;
    }
}

final class InMemoryMemberMediaLookup implements MemberMediaLookupInterface
{
    /**
     * @var array<int, Media>
     */
    private array $mediaById;

    /**
     * @param list<Media> $media
     */
    public function __construct(array $media)
    {
        $this->mediaById = [];
        foreach ($media as $item) {
            $this->mediaById[$item->getId()] = $item;
        }
    }

    public function findByIds(array $ids, bool $onlyVideos = false): array
    {
        $result = [];
        foreach ($ids as $id) {
            $media = $this->mediaById[$id] ?? null;
            if (!$media instanceof Media) {
                continue;
            }

            if ($onlyVideos && !$media->isVideo()) {
                continue;
            }

            $result[] = $media;
        }

        return $result;
    }
}

final class IdentityClusterMemberSelectionService implements ClusterMemberSelectionServiceInterface
{
    public function curate(ClusterDraft $draft): ClusterDraft
    {
        return $draft;
    }
}

final class FirstCoverPicker implements CoverPickerInterface
{
    public function pickCover(array $members, array $clusterParams): ?Media
    {
        return $members[0] ?? null;
    }
}

final class EmptyClusterQueryBuilder
{
    public function select(mixed ...$args): self
    {
        return $this;
    }

    public function from(mixed ...$args): self
    {
        return $this;
    }

    public function where(mixed ...$args): self
    {
        return $this;
    }

    public function andWhere(mixed ...$args): self
    {
        return $this;
    }

    public function setParameter(mixed ...$args): self
    {
        return $this;
    }

    public function getQuery(): object
    {
        return new class() {
            public function getResult(): array
            {
                return [];
            }
        };
    }
}
