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
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Cluster;
use MagicSunday\Memories\Entity\Location;
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

                foreach ($pendingClusters as $entity) {
                    $storedClusters[] = $entity;
                }

                $pendingClusters = [];
            }
        );

        $mediaLookup   = new InMemoryMemberMediaLookup([$mediaOne, $mediaTwo]);
        $memberSelect  = new FailingAfterFirstClusterMemberSelectionService();
        $coverPicker   = new FirstCoverPicker();
        $persistence   = new ClusterPersistenceService(
            em: $clusterEntityManager,
            mediaLookup: $mediaLookup,
            memberSelection: $memberSelect,
            coverPicker: $coverPicker,
            defaultBatchSize: 10,
            maxMembers: 50,
            fingerprintLookupBatchSize: 10,
        );

        $runner  = new DefaultClusterJobRunner($readEntityManager, $clusterer, $consolidator, $persistence);
        $options = new ClusterJobOptions(false, null, null, false);

        try {
            $runner->run($options, new NullProgressReporter());
            self::fail('Expected simulated abort to bubble up.');
        } catch (RuntimeException $exception) {
            self::assertSame('Simulated abort while curating', $exception->getMessage());
        }

        self::assertSame(1, $flushCalls, 'Exactly one cluster flush should complete before aborting.');
        self::assertCount(1, $storedClusters, 'Clusters persisted before abort should remain available.');
        self::assertSame('algo-one', $storedClusters[0]->getAlgorithm());
        self::assertSame(1, $storedClusters[0]->getMembersCount());
    }

    #[Test]
    public function itRollsBackReplacedClustersWhenPersistenceThrows(): void
    {
        $entityManager = $this->createSqliteEntityManager();
        $this->createClusterSchema($entityManager);

        $mediaOne = new Media('/one.jpg', str_pad('1', 64, '0', STR_PAD_LEFT), 1024);
        $mediaOneTakenAt = new DateTimeImmutable('2024-02-10T08:00:00+00:00');
        $mediaOne->setTakenAt($mediaOneTakenAt);
        $mediaOne->setCapturedLocal($mediaOneTakenAt);
        $mediaOne->setFeatureVersion(MetadataFeatureVersion::CURRENT);
        $mediaOne->setMime('image/jpeg');
        $mediaOne->setIsVideo(false);

        $mediaTwo = new Media('/two.jpg', str_pad('2', 64, '0', STR_PAD_LEFT), 1024);
        $mediaTwoTakenAt = new DateTimeImmutable('2024-02-10T09:00:00+00:00');
        $mediaTwo->setTakenAt($mediaTwoTakenAt);
        $mediaTwo->setCapturedLocal($mediaTwoTakenAt);
        $mediaTwo->setFeatureVersion(MetadataFeatureVersion::CURRENT);
        $mediaTwo->setMime('image/jpeg');
        $mediaTwo->setIsVideo(false);

        $entityManager->persist($mediaOne);
        $entityManager->persist($mediaTwo);
        $entityManager->flush();

        $existingCluster = new Cluster('legacy', [], ['lat' => 0.0, 'lon' => 0.0], [$mediaOne->getId()]);
        $entityManager->persist($existingCluster);
        $entityManager->flush();

        $clusterer    = new SequenceHybridClusterer([
            new ClusterDraft('replacement', [], ['lat' => 1.0, 'lon' => 1.0], [$mediaOne->getId(), $mediaTwo->getId()]),
        ]);
        $consolidator = new PassthroughClusterConsolidator();

        $memberLookup  = new InMemoryMemberMediaLookup([$mediaOne, $mediaTwo]);
        $memberSelect  = new IdentityClusterMemberSelectionService();
        $coverPicker   = new NullCoverPicker();
        $persistence   = new ClusterPersistenceService(
            em: $entityManager,
            mediaLookup: $memberLookup,
            memberSelection: $memberSelect,
            coverPicker: $coverPicker,
            defaultBatchSize: 1,
            maxMembers: 50,
            fingerprintLookupBatchSize: 10,
        );

        $runner  = new DefaultClusterJobRunner($entityManager, $clusterer, $consolidator, $persistence);
        $options = new ClusterJobOptions(false, null, null, true);

        try {
            $runner->run($options, new ThrowingProgressReporter('💾 Speichern'));
            self::fail('Expected simulated persistence failure to bubble up.');
        } catch (RuntimeException $exception) {
            self::assertSame('Simulated persistence failure', $exception->getMessage());
        }

        $connection = $entityManager->getConnection();
        $clusterCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM cluster');
        self::assertSame(1, $clusterCount, 'Original clusters should be restored after rollback.');
        $algorithm = $connection->fetchOne('SELECT algorithm FROM cluster LIMIT 1');
        self::assertSame('legacy', $algorithm);
    }

    private function createSqliteEntityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [dirname(__DIR__, 4) . '/src/Entity'],
            isDevMode: true,
        );

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        return EntityManager::create($connection, $config);
    }

    private function createClusterSchema(EntityManagerInterface $entityManager): void
    {
        $schemaTool = new SchemaTool($entityManager);

        $schemaTool->createSchema([
            $entityManager->getClassMetadata(Media::class),
            $entityManager->getClassMetadata(Location::class),
            $entityManager->getClassMetadata(Cluster::class),
        ]);
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

final class FailingAfterFirstClusterMemberSelectionService implements ClusterMemberSelectionServiceInterface
{
    private int $calls = 0;

    public function curate(ClusterDraft $draft): ClusterDraft
    {
        ++$this->calls;

        if ($this->calls > 1) {
            throw new RuntimeException('Simulated abort while curating');
        }

        return $draft;
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

final class NullCoverPicker implements CoverPickerInterface
{
    public function pickCover(array $members, array $clusterParams): ?Media
    {
        return null;
    }
}

final class ThrowingProgressReporter implements ProgressReporterInterface
{
    public function __construct(private readonly string $headlineToThrow)
    {
    }

    public function create(string $sectionTitle, string $headline, int $max): ProgressHandleInterface
    {
        if ($headline === $this->headlineToThrow) {
            return new ThrowingProgressHandle();
        }

        return new NoOpProgressHandle();
    }
}

class NoOpProgressHandle implements ProgressHandleInterface
{
    public function advance(int $step = 1): void
    {
    }

    public function setPhase(?string $message): void
    {
    }

    public function setDetail(?string $message): void
    {
    }

    public function setRate(?string $message): void
    {
    }

    public function setProgress(int $current): void
    {
    }

    public function setMax(int $max): void
    {
    }

    public function createChildHandle(string $sectionTitle, string $headline, int $max): ProgressHandleInterface
    {
        return new self();
    }

    public function finish(): void
    {
    }
}

final class ThrowingProgressHandle extends NoOpProgressHandle
{
    private bool $thrown = false;

    public function advance(int $step = 1): void
    {
        if (!$this->thrown) {
            $this->thrown = true;

            throw new RuntimeException('Simulated persistence failure');
        }
    }

    public function createChildHandle(string $sectionTitle, string $headline, int $max): ProgressHandleInterface
    {
        return new NoOpProgressHandle();
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
