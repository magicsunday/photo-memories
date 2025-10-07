<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\MediaFileLocatorInterface;
use MagicSunday\Memories\Service\Indexing\MediaIngestionPipelineInterface;
use MagicSunday\Memories\Service\Metadata\MetadataQaReportCollector;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function count;
use function is_dir;
use function is_numeric;
use function iterator_to_array;
use function sprintf;

/**
 * Index media files: extract metadata and persist to DB.
 *
 * - Uses a fast extension whitelist to collect files.
 * - MIME detection is centralized via the ingestion pipeline.
 * - Thumbnails are generated only if --thumbnails is provided.
 * - Existing entries are skipped unless --force is used (then they are updated).
 * - Progress bar can be disabled with --no-progress.
 */
#[AsCommand(
    name: 'memories:index',
    description: 'Indexiert Medien: Metadaten extrahieren und in DB speichern. Thumbnails optional mit --thumbnails.'
)]
final class IndexCommand extends Command
{
    public function __construct(
        private readonly MediaFileLocatorInterface $locator,
        private readonly MediaIngestionPipelineInterface $pipeline,
        private readonly MetadataQaReportCollector $qaReportCollector,
        #[Autowire(env: 'MEMORIES_MEDIA_DIR')]
        private readonly string $defaultMediaDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Pfad zum Medienordner (relativ oder absolut).', $this->defaultMediaDir)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Erzwinge Reindexing auch bei vorhandenem Checksum.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, was getan würde, nichts persistieren.')
            ->addOption('max-files', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl Dateien (für Tests).')
            ->addOption('thumbnails', null, InputOption::VALUE_NONE, 'Erstellt Thumbnails (standardmäßig aus).')
            ->addOption('no-progress', null, InputOption::VALUE_NONE, 'Deaktiviert die Fortschrittsanzeige.')
            ->addOption('strict-mime', null, InputOption::VALUE_NONE, 'Validiert zusätzlich den MIME-Type (langsamer).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path       = (string) $input->getArgument('path');
        $force      = (bool) $input->getOption('force');
        $dryRun     = (bool) $input->getOption('dry-run');
        $maxFiles   = $this->toIntOrNull($input->getOption('max-files'));
        $withThumbs = (bool) $input->getOption('thumbnails');
        $noProgress = (bool) $input->getOption('no-progress');
        $strictMime = (bool) $input->getOption('strict-mime');

        if (!is_dir($path)) {
            $output->writeln(sprintf('<error>Pfad existiert nicht oder ist kein Verzeichnis: %s</error>', $path));

            return Command::FAILURE;
        }

        $this->qaReportCollector->reset();

        $output->writeln(sprintf('Starte Indexierung: <info>%s</info>', $path));
        $output->writeln(sprintf('Metadaten-Feature-Version: <info>%d</info>', MetadataFeatureVersion::CURRENT));
        $output->writeln($withThumbs ? '<comment>Thumbnails werden erzeugt.</comment>' : '<comment>Thumbnails werden nicht erzeugt (Option --thumbnails verwenden).</comment>');
        if ($strictMime) {
            $output->writeln('<comment>Strikter MIME-Check ist aktiv.</comment>');
        }

        $files = iterator_to_array($this->locator->locate($path, $maxFiles));
        $total = count($files);

        if ($total === 0) {
            $output->writeln('<comment>Keine passenden Dateien gefunden.</comment>');

            return Command::SUCCESS;
        }

        $progress = null;
        if ($noProgress === false) {
            $progress = new ProgressBar($output, $total);
            $progress->setFormat('%current%/%max% [%bar%] %percent:3s%% | Dauer: %elapsed:6s% | ETA: %estimated:-6s% | Datei: %filename%');
            $progress->setMessage('', 'filename');
            $progress->start();
        }

        $count = 0;

        foreach ($files as $filepath) {
            if ($progress instanceof ProgressBar) {
                $progress->setMessage($filepath, 'filename');
            }

            $output->writeln('Verarbeite: ' . $filepath, OutputInterface::VERBOSITY_VERBOSE);

            $result = $this->pipeline->process(
                $filepath,
                $force,
                $dryRun,
                $withThumbs,
                $strictMime,
                $output,
            );

            if ($result instanceof Media) {
                ++$count;
            }

            if ($progress instanceof ProgressBar) {
                $progress->advance();
            }
        }

        $this->pipeline->finalize($dryRun);

        if ($progress instanceof ProgressBar) {
            $progress->finish();
            $output->writeln('');
        }

        $output->writeln(sprintf('<info>Indexierung abgeschlossen. Insgesamt verarbeitete Dateien: %d</info>', $count));
        $this->qaReportCollector->render($output);
        $this->qaReportCollector->reset();

        return Command::SUCCESS;
    }

    private function toIntOrNull(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }

        if (is_numeric($v)) {
            return (int) $v;
        }

        return null;
    }
}
