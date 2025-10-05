<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use DateTimeImmutable;
use MagicSunday\Memories\Service\Clusterer\ClusterJobOptions;
use MagicSunday\Memories\Service\Clusterer\ConsoleProgressReporter;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterJobRunnerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function ctype_digit;
use function is_string;
use function sprintf;

/**
 * Class ClusterCommand
 */
#[AsCommand(
    name: 'memories:cluster',
    description: 'Erstellt Erinnerungs-Cluster anhand konfigurierter Strategien.'
)]
final readonly class ClusterCommand extends Command
{
    public function __construct(private readonly ClusterJobRunnerInterface $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur berechnen, nicht speichern')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl Medien')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Nur Medien ab Datum (YYYY-MM-DD)')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Bestehende Cluster vor dem Speichern löschen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $limit   = $input->getOption('limit');
        $since   = $input->getOption('since');
        $replace = (bool) $input->getOption('replace');

        $io->title('🧠 Memories: Cluster erstellen');

        $sinceValue = null;
        if (is_string($since) && $since !== '') {
            $sinceValue = DateTimeImmutable::createFromFormat('Y-m-d|', $since);
            if (!$sinceValue instanceof DateTimeImmutable) {
                $io->error('Invalid "since" date. Use YYYY-MM-DD.');

                return Command::INVALID;
            }
        }

        $limitValue = null;
        if (is_string($limit) && $limit !== '') {
            if (!ctype_digit($limit)) {
                $io->error('Invalid "limit" value. Use a positive integer.');

                return Command::INVALID;
            }

            $limitAsInt = (int) $limit;
            if ($limitAsInt > 0) {
                $limitValue = $limitAsInt;
            }
        }

        $options = new ClusterJobOptions($dryRun, $limitValue, $sinceValue, $replace);

        $progressReporter = new ConsoleProgressReporter($io, $output);
        $result            = $this->runner->run($options, $progressReporter);

        if ($result->getTotalMediaCount() === 0) {
            $io->warning('Keine Medien gefunden.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d Medien geladen.', $result->getLoadedMediaCount()));
        if ($result->getLoadedMediaCount() === 0) {
            $io->note('Keine Medien zum Clustern vorhanden.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d Cluster vorgeschlagen.', $result->getDraftCount()));
        if ($result->getDraftCount() === 0) {
            $io->note('Keine Cluster zu speichern.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d → %d Cluster nach Konsolidierung.', $result->getDraftCount(), $result->getConsolidatedCount()));

        if (!$options->isDryRun() && $options->shouldReplace() && $result->getDeletedCount() > 0) {
            $io->info(sprintf('%d bestehende Cluster gelöscht.', $result->getDeletedCount()));
        }

        $io->success(sprintf('%d Cluster gespeichert.', $result->getPersistedCount()));

        return Command::SUCCESS;
    }
}
