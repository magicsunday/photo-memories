<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Command\ClusterCurationMigrationCommand;
use MagicSunday\Memories\Entity\Cluster;
use MagicSunday\Memories\Repository\ClusterRepository;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterCuratedOverlayRefresherInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function array_filter;
use function array_values;
use function in_array;

final class ClusterCurationMigrationCommandTest extends TestCase
{
    #[Test]
    public function executeSkipsWhenNoClustersAreAvailable(): void
    {
        $repository = $this->createRepository([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getConnection');
        $entityManager->expects(self::never())->method('flush');

        $refresher = $this->createMock(ClusterCuratedOverlayRefresherInterface::class);
        $refresher->expects(self::never())->method('refreshExistingCluster');

        $command = new ClusterCurationMigrationCommand($entityManager, $repository, $refresher);
        $tester  = new CommandTester($command);

        $status = $tester->execute([], ['decorated' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Keine Cluster zum Migrieren gefunden.', $tester->getDisplay());
    }

    #[Test]
    public function executeProcessesClustersInDryRunMode(): void
    {
        $clusterA = new Cluster('story', 'demo', [], ['lat' => 0.0, 'lon' => 0.0], [1]);
        $clusterB = new Cluster('story', 'other', [], ['lat' => 0.0, 'lon' => 0.0], [2]);

        $repository = $this->createRepository([$clusterA, $clusterB]);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getConnection')->willReturn($connection);
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::once())->method('clear');

        $refresher = $this->createMock(ClusterCuratedOverlayRefresherInterface::class);
        $refresher->expects(self::once())
            ->method('refreshExistingCluster')
            ->with($clusterA)
            ->willReturn([
                'raw_count'     => 5,
                'curated_count' => 3,
                'overlay_count' => 3,
            ]);

        $command = new ClusterCurationMigrationCommand($entityManager, $repository, $refresher);
        $tester  = new CommandTester($command);

        $status = $tester->execute([
            '--algorithm' => ['demo'],
            '--batch-size' => '10',
            '--dry-run' => true,
        ], ['decorated' => false]);

        self::assertSame(Command::SUCCESS, $status);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry-Run: Änderungen werden nach dem Lauf verworfen.', $display);
        self::assertStringContainsString('1 Cluster aktualisiert.', $display);
        self::assertStringContainsString('Mitglieder (roh)', $display);
        self::assertStringContainsString('5', $display);
        self::assertStringContainsString('Mitglieder (kuratiert)', $display);
        self::assertStringContainsString('Kuratiertes Overlay', $display);
    }

    /**
     * @param list<Cluster> $clusters
     */
    private function createRepository(array $clusters): ClusterRepository
    {
        $em = $this->createMock(EntityManagerInterface::class);

        return new TestClusterRepository($em, $clusters);
    }
}

/**
 * @internal Helper stub for repository behaviour during command tests
 */
readonly class TestClusterRepository extends ClusterRepository
{
    /**
     * @param list<Cluster> $clusters
     */
    public function __construct(EntityManagerInterface $em, private array $clusters)
    {
        parent::__construct($em);
    }

    public function countByAlgorithms(?array $algorithms = null): int
    {
        return count($this->filter($algorithms));
    }

    public function iterateByAlgorithms(?array $algorithms = null): iterable
    {
        foreach ($this->filter($algorithms) as $cluster) {
            yield $cluster;
        }
    }

    /**
     * @return list<Cluster>
     */
    private function filter(?array $algorithms): array
    {
        if ($algorithms === null) {
            return $this->clusters;
        }

        return array_values(array_filter(
            $this->clusters,
            static fn (Cluster $cluster): bool => in_array($cluster->getAlgorithm(), $algorithms, true),
        ));
    }
}
