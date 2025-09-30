<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\ClusterJobOptions;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidatorInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterPersistenceInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\HybridClustererInterface;
use MagicSunday\Memories\Service\Clusterer\DefaultClusterJobRunner;
use MagicSunday\Memories\Service\Clusterer\NullProgressReporter;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DefaultClusterJobRunnerTest extends TestCase
{
    #[Test]
    public function runReturnsEarlyWhenNoMedia(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $countQb = $this->createQueryBuilderMock(['select', 'from', 'andWhere', 'setParameter', 'getQuery']);
        $countQuery = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $countQuery->expects(self::once())->method('getSingleScalarResult')->willReturn('0');
        $countQb->method('getQuery')->willReturn($countQuery);

        $listQb = $this->createQueryBuilderMock(['select', 'from', 'orderBy', 'addOrderBy', 'andWhere', 'setParameter', 'setMaxResults', 'getQuery']);
        $listQb->expects(self::never())->method('getQuery');

        $entityManager->expects(self::exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($countQb, $listQb);

        $clusterer = $this->createMock(HybridClustererInterface::class);
        $clusterer->expects(self::never())->method('countStrategies');
        $clusterer->expects(self::never())->method('build');

        $consolidator = $this->createMock(ClusterConsolidatorInterface::class);
        $consolidator->expects(self::never())->method('consolidate');

        $persistence = $this->createMock(ClusterPersistenceInterface::class);
        $persistence->expects(self::never())->method('deleteByAlgorithms');
        $persistence->expects(self::never())->method('persistBatched');

        $runner  = new DefaultClusterJobRunner($entityManager, $clusterer, $consolidator, $persistence);
        $options = new ClusterJobOptions(false, null, null, false);

        $result = $runner->run($options, new NullProgressReporter());

        self::assertSame(0, $result->getTotalMediaCount());
        self::assertSame(0, $result->getLoadedMediaCount());
        self::assertSame(0, $result->getDraftCount());
        self::assertSame(0, $result->getConsolidatedCount());
        self::assertSame(0, $result->getPersistedCount());
        self::assertSame(0, $result->getDeletedCount());
        self::assertFalse($result->isDryRun());
    }

    #[Test]
    public function runPersistsConsolidatedDrafts(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $countQb = $this->createQueryBuilderMock(['select', 'from', 'andWhere', 'setParameter', 'getQuery']);
        $countQuery = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $countQuery->expects(self::once())->method('getSingleScalarResult')->willReturn('2');
        $countQb->method('getQuery')->willReturn($countQuery);

        $listQb = $this->createQueryBuilderMock(['select', 'from', 'orderBy', 'addOrderBy', 'andWhere', 'setParameter', 'setMaxResults', 'getQuery']);
        $mediaOne = new Media('one.jpg', 'checksum-one', 1);
        $mediaTwo = new Media('two.jpg', 'checksum-two', 1);
        $listQuery = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toIterable'])
            ->getMock();
        $listQuery->expects(self::once())->method('toIterable')->willReturn([$mediaOne, $mediaTwo]);
        $listQb->method('getQuery')->willReturn($listQuery);

        $entityManager->expects(self::exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($countQb, $listQb);

        $drafts = [
            new ClusterDraft('algo-a', [], ['lat' => 0.0, 'lon' => 0.0], [1, 2]),
            new ClusterDraft('algo-b', [], ['lat' => 0.0, 'lon' => 0.0], [3, 4]),
        ];
        $consolidatedDrafts = [
            new ClusterDraft('algo-a', [], ['lat' => 0.0, 'lon' => 0.0], [1, 2]),
        ];

        $clusterer = $this->createMock(HybridClustererInterface::class);
        $clusterer->expects(self::once())->method('countStrategies')->willReturn(1);
        $clusterer->expects(self::once())
            ->method('build')
            ->willReturnCallback(function (array $items, callable $onStart, callable $onDone) use ($drafts): array {
                self::assertCount(2, $items);
                $onStart('Strategy', 1, 1);
                $onDone('Strategy', 1, 1);

                return $drafts;
            });

        $consolidator = $this->createMock(ClusterConsolidatorInterface::class);
        $consolidator->expects(self::once())
            ->method('consolidate')
            ->willReturnCallback(function (array $inputDrafts, callable $callback) use ($consolidatedDrafts): array {
                self::assertCount(2, $inputDrafts);
                $callback(1, 2, 'stage');

                return $consolidatedDrafts;
            });

        $persistence = $this->createMock(ClusterPersistenceInterface::class);
        $persistence->expects(self::once())
            ->method('deleteByAlgorithms')
            ->with(['algo-a'])
            ->willReturn(5);
        $persistence->expects(self::once())
            ->method('persistBatched')
            ->willReturnCallback(function (array $persistedDrafts, int $batchSize, ?callable $callback): int {
                self::assertSame(250, $batchSize);
                self::assertCount(1, $persistedDrafts);
                self::assertNotNull($callback);
                $callback(1);

                return 1;
            });

        $runner  = new DefaultClusterJobRunner($entityManager, $clusterer, $consolidator, $persistence);
        $options = new ClusterJobOptions(false, null, null, true);

        $result = $runner->run($options, new NullProgressReporter());

        self::assertSame(2, $result->getTotalMediaCount());
        self::assertSame(2, $result->getLoadedMediaCount());
        self::assertSame(2, $result->getDraftCount());
        self::assertSame(1, $result->getConsolidatedCount());
        self::assertSame(1, $result->getPersistedCount());
        self::assertSame(5, $result->getDeletedCount());
        self::assertFalse($result->isDryRun());
    }

    #[Test]
    public function runPerformsDryRunWithoutPersistence(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $countQb = $this->createQueryBuilderMock(['select', 'from', 'andWhere', 'setParameter', 'getQuery']);
        $countQuery = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $countQuery->expects(self::once())->method('getSingleScalarResult')->willReturn('2');
        $countQb->method('getQuery')->willReturn($countQuery);

        $listQb = $this->createQueryBuilderMock(['select', 'from', 'orderBy', 'addOrderBy', 'andWhere', 'setParameter', 'setMaxResults', 'getQuery']);
        $mediaOne = new Media('dry-one.jpg', 'checksum-dry-one', 1);
        $mediaTwo = new Media('dry-two.jpg', 'checksum-dry-two', 1);
        $listQuery = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toIterable'])
            ->getMock();
        $listQuery->expects(self::once())->method('toIterable')->willReturn([$mediaOne, $mediaTwo]);
        $listQb->method('getQuery')->willReturn($listQuery);

        $entityManager->expects(self::exactly(2))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($countQb, $listQb);

        $drafts = [
            new ClusterDraft('algo-a', [], ['lat' => 0.0, 'lon' => 0.0], [1]),
            new ClusterDraft('algo-b', [], ['lat' => 0.0, 'lon' => 0.0], [2]),
        ];

        $clusterer = $this->createMock(HybridClustererInterface::class);
        $clusterer->expects(self::once())->method('countStrategies')->willReturn(2);
        $clusterer->expects(self::once())
            ->method('build')
            ->willReturnCallback(function (array $items, callable $onStart, callable $onDone) use ($drafts): array {
                $onStart('Strategy A', 1, 2);
                $onDone('Strategy A', 1, 2);

                return $drafts;
            });

        $consolidator = $this->createMock(ClusterConsolidatorInterface::class);
        $consolidator->expects(self::once())
            ->method('consolidate')
            ->willReturnCallback(function (array $inputDrafts, callable $callback): array {
                $callback(2, 2, 'stage');

                return $inputDrafts;
            });

        $persistence = $this->createMock(ClusterPersistenceInterface::class);
        $persistence->expects(self::never())->method('deleteByAlgorithms');
        $persistence->expects(self::never())->method('persistBatched');

        $runner  = new DefaultClusterJobRunner($entityManager, $clusterer, $consolidator, $persistence);
        $options = new ClusterJobOptions(true, null, null, true);

        $result = $runner->run($options, new NullProgressReporter());

        self::assertSame(2, $result->getTotalMediaCount());
        self::assertSame(2, $result->getLoadedMediaCount());
        self::assertSame(2, $result->getDraftCount());
        self::assertSame(2, $result->getConsolidatedCount());
        self::assertSame(2, $result->getPersistedCount());
        self::assertSame(0, $result->getDeletedCount());
        self::assertTrue($result->isDryRun());
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
