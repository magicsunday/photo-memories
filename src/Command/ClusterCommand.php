<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\ClusterConsolidationService;
use MagicSunday\Memories\Service\Clusterer\ClusterPersistenceService;
use MagicSunday\Memories\Service\Clusterer\HybridClusterer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'memories:cluster',
    description: 'Erstellt Erinnerungs-Cluster anhand konfigurierter Strategien.'
)]
final class ClusterCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HybridClusterer $clusterer,
        private readonly ClusterPersistenceService $persistence,
        private readonly ClusterConsolidationService $consolidation
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur berechnen, nicht speichern')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl Medien', null)
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Nur Medien ab Datum (YYYY-MM-DD)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = $input->getOption('limit');
        $since  = $input->getOption('since');

        $io->title('🧠 Memories: Cluster erstellen');

        // 1) Medien laden
        $io->section('Medien laden');

        $countQb = $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Media::class, 'm');

        $listQb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->orderBy('m.takenAt', 'ASC')
            ->addOrderBy('m.id', 'ASC');

        if (\is_string($since) && $since !== '') {
            $sinceDt = DateTimeImmutable::createFromFormat('Y-m-d|', $since);
            if (!$sinceDt instanceof DateTimeImmutable) {
                $io->error('Invalid "since" date. Use YYYY-MM-DD.');
                return Command::INVALID;
            }
            $countQb->andWhere('m.takenAt >= :since')->setParameter('since', $sinceDt);
            $listQb->andWhere('m.takenAt >= :since')->setParameter('since', $sinceDt);
        }

        if (\is_string($limit) && $limit !== '') {
            if (!\ctype_digit($limit)) {
                $io->error('Invalid "limit" value. Use a positive integer.');
                return Command::INVALID;
            }
            $lim = (int) $limit;
            if ($lim > 0) {
                $listQb->setMaxResults($lim);
            }
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        if ($total === 0) {
            $io->warning('Keine Medien gefunden.');
            return Command::SUCCESS;
        }

        $loadSection = $output->section();
        $loadBar     = $this->makeBar($loadSection, $total, '📥 Einlesen');
        $loadStart   = \microtime(true);

        /** @var list<Media> $items */
        $items = [];
        foreach ($listQb->getQuery()->toIterable() as $row) {
            /** @var Media $m */
            $m = $row;
            $items[] = $m;
            $this->tick($loadBar, $loadStart, \count($items), 'Medien');
        }
        $loadBar->finish();
        $loadSection->writeln('');
        $io->success(\sprintf('%d Medien geladen.', \count($items)));

        if (\count($items) === 0) {
            $io->note('Keine Medien zum Clustern vorhanden.');
            return Command::SUCCESS;
        }

        // 2) Clustern – Hauptbalken + pro Strategie Unterbalken
        $io->section('Clustere');

        $strategyCount = $this->clusterer->countStrategies();

        $mainSection = $output->section();
        $mainBar     = $this->makeBar($mainSection, $strategyCount, '🧩 Strategien');
        $mainStart   = \microtime(true);

        // Hauptleiste: bei Start nur Text setzen, bei Done einen Schritt vorwärts
        $onStart = function (string $strategyName, int $index, int $total) use ($mainBar): void {
            $mainBar->setMessage(\sprintf('Strategie: %s (%d/%d)', $strategyName, $index, $total), 'phase');
            $mainBar->setMessage('–', 'rate');
            // kein advance hier – erst bei Done
        };

        $onDone = function (string $strategyName, int $index, int $total) use ($mainBar, $mainStart, $items): void {
            $elapsed = \max(0.000001, \microtime(true) - $mainStart);
            $rate    = \count($items) / $elapsed;
            $mainBar->setMessage(\sprintf('Strategie: %s (%d/%d)', $strategyName, $index, $total), 'phase');
            $mainBar->setMessage(\sprintf('Durchsatz: %.1f Medien/s', $rate), 'rate');
            $mainBar->advance(); // jetzt 1 Schritt weiter
        };

        /** @var list<ClusterDraft> $drafts */
        $drafts = $this->clusterer->build(
            $items,
            $onStart,
            $onDone,
        );

        $mainBar->finish();
        $mainSection->writeln('');
        $io->success(\sprintf('%d Cluster vorgeschlagen.', \count($drafts)));

        if (\count($drafts) === 0) {
            $io->note('Keine Cluster zu speichern.');
            return Command::SUCCESS;
        }

        $io->section('Konsolidieren');

        $before = \count($drafts);
        $consolSection = $output->section();
        $consolBar = $this->makeBar($consolSection, $before, '🧹 Konsolidieren');
        $conStart = \microtime(true);

        $drafts = $this->consolidation->consolidate(
            $drafts,
            function (int $done, int $max, string $stage) use ($consolBar, $conStart): void {
                $elapsed = \max(
                    0.000001,
                    \microtime(true) - $conStart
                );
                $rate = $done / $elapsed;
                $consolBar->setMessage(
                    \sprintf(
                        '%s | Durchsatz: %.1f Schritte/s',
                        $stage,
                        $rate
                    ),
                    'rate'
                );
                if (\method_exists(
                    $consolBar,
                    'setProgress'
                )) {
                    $consolBar->setProgress($done);
                } else {
                    $consolBar->advance();
                }
            }
        );

        $consolBar->finish();
        $consolSection->writeln('');
        $io->success(\sprintf('%d → %d Cluster nach Konsolidierung.', $before, \count($drafts)));

        // 3) Persistieren
        $io->section($dryRun ? 'Persistieren (Trockenlauf)' : 'Persistieren');

        $persistSection = $output->section();
        $persistBar     = $this->makeBar($persistSection, \count($drafts), '💾 Speichern');
        $persistStart   = \microtime(true);

        $persisted = 0;
        if ($dryRun) {
            foreach ($drafts as $_) {
                $persisted++;
                $this->tick($persistBar, $persistStart, $persisted, 'Cluster');
            }
        } else {
            $persisted = $this->persistence->persistBatched(
                $drafts,
                250,
                function (int $ok) use ($persistBar, $persistStart, &$persisted): void {
                    $persisted += $ok;
                    $this->tick($persistBar, $persistStart, $persisted, 'Cluster');
                }
            );
        }

        $persistBar->finish();
        $persistSection->writeln('');
        $io->success(\sprintf('%d Cluster gespeichert.', $persisted));

        return Command::SUCCESS;
    }

    /**
     * Create a progress bar with stage text and live throughput.
     * Uses named messages %phase% and %rate% (Console 7.3+).
     */
    private function makeBar(ConsoleSectionOutput $section, int $max, string $headline): ProgressBar
    {
        $bar = new ProgressBar($section, $max);

        // Own duration placeholder to avoid version-specific APIs
        $startedAt = \microtime(true);
        ProgressBar::setPlaceholderFormatterDefinition('duration_hms', static function () use ($startedAt): string {
            $elapsed = (int) \max(0, \microtime(true) - $startedAt);
            $h = (int) \floor($elapsed / 3600);
            $m = (int) \floor(($elapsed % 3600) / 60);
            $s = $elapsed % 60;
            return \sprintf('%02d:%02d:%02d', $h, $m, (int) $s);
        });

        // Named messages: %phase% and %rate%
        $bar->setFormat(\sprintf(
            "%s\n%%current%%/%%max%% [%%bar%%] %%percent%%%% | Dauer: %%duration_hms%% | ETA: %%remaining%% | %%phase%% | %%rate%%",
            $headline
        ));
        $bar->setBarCharacter('=');
        $bar->setEmptyBarCharacter(' ');
        $bar->setProgressCharacter('>');
        $bar->setRedrawFrequency(1);

        // init named messages
        $bar->setMessage('', 'phase');
        $bar->setMessage('–', 'rate');

        return $bar;
    }

    private function tick(ProgressBar $bar, float $startTs, int $processed, string $unit): void
    {
        $elapsed = \max(0.000001, \microtime(true) - $startTs);
        $rate    = $processed / $elapsed;
        $bar->setMessage(\sprintf('Durchsatz: %.1f %s/s', $rate, $unit));
        $bar->advance();
    }
}
