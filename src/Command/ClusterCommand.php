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
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;
use MagicSunday\Memories\Service\Clusterer\ClusterJobOptions;
use MagicSunday\Memories\Service\Clusterer\ClusterJobTelemetry;
use MagicSunday\Memories\Service\Clusterer\ConsoleProgressReporter;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterJobRunnerInterface;
use MagicSunday\Memories\Service\Clusterer\Debug\VacationDebugContext;
use MagicSunday\Memories\Service\Clusterer\ClusterSummaryTimeRange;
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
 * Class ClusterCommand.
 */
#[AsCommand(
    name: 'memories:cluster',
    description: 'Erstellt Erinnerungs-Cluster anhand konfigurierter Strategien.'
)]
final class ClusterCommand extends Command
{
    use SelectionOverrideInputTrait;

    public function __construct(
        private readonly ClusterJobRunnerInterface $runner,
        private readonly SelectionProfileProvider $selectionProfiles,
        private readonly ?VacationDebugContext $vacationDebugContext = null,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur berechnen, nicht speichern')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl Medien')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Nur Medien ab Datum (YYYY-MM-DD)')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Bestehende Cluster vor dem Speichern löschen')
            ->addOption('debug-vacation', null, InputOption::VALUE_NONE, 'Debug-Ausgabe für Urlaubssegmente aktivieren');

        $this->configureSelectionOverrideOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $limit   = $input->getOption('limit');
        $since   = $input->getOption('since');
        $replace = (bool) $input->getOption('replace');
        $debugVacation = (bool) $input->getOption('debug-vacation');

        $debugContextActive = false;
        if ($this->vacationDebugContext !== null) {
            if ($debugVacation) {
                $this->vacationDebugContext->enable();
                $this->vacationDebugContext->reset();
                $debugContextActive = true;
            } else {
                $this->vacationDebugContext->disable();
            }
        }

        $io->title('🧠 Memories: Cluster erstellen');

        try {
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

            try {
                $selectionOverrides = $this->resolveSelectionOverrides($input);
            } catch (InvalidArgumentException $exception) {
                $io->error($exception->getMessage());

                return Command::INVALID;
            }

            $this->selectionProfiles->setRuntimeOverrides($selectionOverrides);

            $options = new ClusterJobOptions($dryRun, $limitValue, $sinceValue, $replace, $debugVacation);

            $progressReporter = new ConsoleProgressReporter($io, $output);
            $result           = $this->runner->run($options, $progressReporter);

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

            $this->renderTelemetry($io, $result->getTelemetry());

            return Command::SUCCESS;
        } finally {
            if ($debugContextActive) {
                $this->vacationDebugContext?->disable();
            }
        }
    }

    private function renderTelemetry(SymfonyStyle $io, ClusterJobTelemetry $telemetry): void
    {
        $stageStats = $telemetry->getStageStats();
        $topClusters = $telemetry->getTopClusters();
        $warnings    = $telemetry->getWarnings();

        if ($warnings === [] && $stageStats === [] && $topClusters === []) {
            return;
        }

        $io->section('📊 Telemetrie');

        if ($warnings !== []) {
            foreach ($warnings as $warning) {
                $io->warning($warning);
            }
        }

        if ($stageStats !== []) {
            $rows = [];
            $labels = [
                ClusterJobTelemetry::STAGE_DRAFTS => 'Entwürfe',
                ClusterJobTelemetry::STAGE_CONSOLIDATED => 'Konsolidiert',
            ];

            foreach ($labels as $stage => $label) {
                $stat = $stageStats[$stage] ?? null;
                if ($stat === null) {
                    continue;
                }

                $rows[] = [
                    $label,
                    (string) $stat['clusters'],
                    (string) $stat['members_pre'],
                    (string) $stat['members_post'],
                ];
            }

            if ($rows !== []) {
                $io->table(
                    ['Phase', 'Cluster', 'Mitglieder vor (roh)', 'Mitglieder nach (roh)'],
                    $rows,
                );
            }
        }

        if ($topClusters === []) {
            return;
        }

        $rows = [];
        foreach ($topClusters as $summary) {
            $score     = $summary->getScore();
            $timeRange = $summary->getTimeRange();
            $policy    = $summary->getPolicyKey();

            $rows[] = [
                $summary->getAlgorithm(),
                $summary->getStoryline(),
                $policy !== null && $policy !== '' ? $policy : '–',
                (string) $summary->getRawMemberCount(),
                (string) $summary->getCuratedMemberCount(),
                $score !== null ? sprintf('%.2f', $score) : '–',
                $timeRange instanceof ClusterSummaryTimeRange
                    ? $this->formatTelemetryRange($timeRange)
                    : '–',
            ];
        }

        $io->table(['Algorithmus', 'Storyline', 'Profil', 'Mitglieder (roh)', 'Mitglieder (kuratiert)', 'Score', 'Zeitraum'], $rows);
    }

    private function formatTelemetryRange(ClusterSummaryTimeRange $range): string
    {
        $timezone = new DateTimeZone('UTC');
        $from     = $range->getFrom()->setTimezone($timezone)->format('Y-m-d');
        $to       = $range->getTo()->setTimezone($timezone)->format('Y-m-d');

        if ($from === $to) {
            return $from;
        }

        return sprintf('%s → %s', $from, $to);
    }
}
