<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Repository\ClusterRepository;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterCuratedOverlayRefresherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;
use function max;
use function sprintf;

#[AsCommand(
    name: 'memories:cluster:migrate-curation',
    description: 'Aktualisiert persistierte Cluster mit kuratiertem Overlay.',
)]
final class ClusterCurationMigrationCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClusterRepository $clusterRepository,
        private readonly ClusterCuratedOverlayRefresherInterface $overlayRefresher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'algorithm',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Nur Cluster dieser Algorithmen migrieren (Option mehrfach angeben).',
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Anzahl Cluster pro Flush.',
                '50',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Nur simulieren, Änderungen werden verworfen.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🧠 Memories: Cluster-Kuration migrieren');

        $algorithmOption = $input->getOption('algorithm');
        $algorithms = [];
        if (is_array($algorithmOption)) {
            $algorithms = array_values(array_filter(
                $algorithmOption,
                static fn ($value): bool => is_string($value) && $value !== '',
            ));
        }

        $batchSize = (int) $input->getOption('batch-size');
        $batchSize = max(1, $batchSize);
        $dryRun    = (bool) $input->getOption('dry-run');

        $algorithmFilter = $algorithms !== [] ? $algorithms : null;
        $total            = $this->clusterRepository->countByAlgorithms($algorithmFilter);

        if ($total === 0) {
            $io->success('Keine Cluster zum Migrieren gefunden.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note('Dry-Run: Änderungen werden nach dem Lauf verworfen.');
        }

        $progress = $io->createProgressBar($total);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %message%');
        $progress->setMessage('Starte Migration …');
        $progress->start();

        $connection = $this->entityManager->getConnection();
        if (!$connection instanceof Connection) {
            throw new \RuntimeException('Datenbankverbindung konnte nicht initialisiert werden.');
        }

        $connection->beginTransaction();

        $processed      = 0;
        $rawTotal       = 0;
        $curatedTotal   = 0;
        $overlayTotal   = 0;

        try {
            foreach ($this->clusterRepository->iterateByAlgorithms($algorithmFilter) as $cluster) {
                ++$processed;

                $result = $this->overlayRefresher->refreshExistingCluster($cluster);
                $rawTotal     += $result['raw_count'];
                $curatedTotal += $result['curated_count'];
                $overlayTotal += $result['overlay_count'];

                if ($processed % $batchSize === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }

                $progress->setMessage(sprintf('Cluster %d verarbeitet', $processed));
                $progress->advance();
            }

            $this->entityManager->flush();

            if ($dryRun) {
                $connection->rollBack();
                $this->entityManager->clear();
            } else {
                $connection->commit();
            }

            $progress->setMessage('Abgeschlossen');
            $progress->finish();
            $io->newLine(2);

            $io->success(sprintf('%d Cluster aktualisiert.', $processed));
            $io->table(
                ['Metrik', 'Anzahl'],
                [
                    ['Mitglieder (roh)', (string) $rawTotal],
                    ['Mitglieder (kuratiert)', (string) $curatedTotal],
                    ['Kuratiertes Overlay', (string) $overlayTotal],
                ],
            );

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $progress->finish();
            $io->newLine();
            $connection->rollBack();

            throw $exception;
        }
    }
}
