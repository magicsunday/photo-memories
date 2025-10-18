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
use InvalidArgumentException;
use MagicSunday\Memories\Service\Clusterer\ClusterJobOptions;
use MagicSunday\Memories\Service\Clusterer\ClusterJobResult;
use MagicSunday\Memories\Service\Clusterer\ConsoleProgressReporter;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterJobRunnerInterface;
use MagicSunday\Memories\Service\Feed\Contract\FeedExportServiceInterface;
use MagicSunday\Memories\Service\Feed\FeedExportRequest;
use MagicSunday\Memories\Service\Feed\FeedExportStage;
use MagicSunday\Memories\Service\Indexing\MediaFileLocatorInterface;
use MagicSunday\Memories\Service\Indexing\MediaIngestionPipelineInterface;
use MagicSunday\Memories\Service\Metadata\MetadataFeatureVersion;
use MagicSunday\Memories\Service\Metadata\MetadataQaReportCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_dir;
use function is_string;
use function iterator_to_array;
use function sprintf;
use function strtolower;
use function trim;

/**
 * End-to-end orchestration of the indexing → clustering → feed export pipeline.
 */
#[AsCommand(
    name: 'memories:curate',
    description: 'Führt Indexierung, Clustering, Kuration und Feed-Export sequenziell aus.'
)]
final class MemoriesCurateCommand extends Command
{
    private const REINDEX_AUTO = 'auto';
    private const REINDEX_FORCE = 'force';
    private const REINDEX_SKIP = 'skip';

    /**
     * @var list<string>
     */
    private array $allowedGroups;

    /**
     * @var array<string,string>
     */
    private array $groupAliases;

    /**
     * @var list<string>
     */
    private array $friendlyNames;

    /**
     * @param array<string,string> $clusterGroupMap
     * @param array<string,string> $clusterGroupAliasMap
     */
    public function __construct(
        private readonly MediaFileLocatorInterface $fileLocator,
        private readonly MediaIngestionPipelineInterface $pipeline,
        private readonly MetadataQaReportCollector $qaReportCollector,
        private readonly ClusterJobRunnerInterface $clusterJobRunner,
        private readonly FeedExportServiceInterface $feedExportService,
        #[Autowire('%memories.cluster.consolidate.groups%')]
        private readonly array $clusterGroupMap,
        #[Autowire('%memories.cluster.consolidate.group_aliases%')]
        private readonly array $clusterGroupAliasMap,
        #[Autowire(env: 'MEMORIES_MEDIA_DIR')]
        private readonly string $defaultMediaDir,
    ) {
        parent::__construct();

        $groups = array_values($this->clusterGroupMap);
        $groups = array_map(static fn (mixed $value): ?string => is_string($value) ? $value : null, $groups);
        $this->allowedGroups = array_values(array_unique(array_filter($groups))); // keep deterministic order

        $aliases = [];
        foreach ($this->clusterGroupAliasMap as $alias => $group) {
            if (!is_string($alias) || !is_string($group)) {
                continue;
            }

            $aliasKey = strtolower(trim($alias));
            if ($aliasKey === '' || !in_array($group, $this->allowedGroups, true)) {
                continue;
            }

            $aliases[$aliasKey] = $group;
        }

        $this->groupAliases = $aliases;
        $this->friendlyNames = array_keys($aliases);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Medienverzeichnis für die Indexierung.', $this->defaultMediaDir)
            ->addOption('types', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Erlaube nur diese Erinnerungstypen (Cluster-Gruppen).')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Nur Medien ab Datum (YYYY-MM-DD) berücksichtigen.')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Nur Medien bis Datum (YYYY-MM-DD) berücksichtigen.')
            ->addOption('reindex', null, InputOption::VALUE_OPTIONAL, 'Steuert die Metadaten-Indexierung (auto|force|skip).', self::REINDEX_AUTO)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur Simulation: keine Änderungen in der Datenbank und kein Export.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('✨ Memories: Vollständige Kurationspipeline');

        $mediaPath = (string) $input->getArgument('path');

        if (!is_dir($mediaPath)) {
            $io->error(sprintf('Pfad existiert nicht oder ist kein Verzeichnis: %s', $mediaPath));

            return Command::INVALID;
        }

        try {
            $allowedGroups = $this->parseTypesOption($input->getOption('types'));
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        try {
            $since = $this->parseDateOption($input->getOption('since'), 'since');
            $until = $this->parseDateOption($input->getOption('until'), 'until');
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if ($since instanceof DateTimeImmutable && $until instanceof DateTimeImmutable && $since > $until) {
            $io->error('Das Startdatum darf nicht nach dem Enddatum liegen.');

            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        $reindexOption = $input->getOption('reindex');
        $reindexStrategy = $this->normaliseReindexStrategy($reindexOption);
        if ($reindexStrategy === null) {
            $io->error('Ungültige Reindex-Strategie. Erlaubt sind auto, force oder skip.');

            return Command::INVALID;
        }

        if ($allowedGroups !== null && $allowedGroups !== []) {
            $io->note(sprintf('Filter aktive Cluster-Gruppen: %s', implode(', ', $allowedGroups)));
        }

        try {
            $this->runIndexingStage($io, $output, $mediaPath, $reindexStrategy, $dryRun);
        } catch (Throwable $exception) {
            $io->error(sprintf('Indexierung fehlgeschlagen: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $result = $this->runClusteringStage($io, $output, $dryRun, $since, $until, $allowedGroups);
        if (!$result instanceof ClusterJobResult) {
            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->info('Dry-Run: Feed-Export übersprungen.');

            return Command::SUCCESS;
        }

        return $this->runFeedExportStage($io);
    }

    /**
     * @param array<int,string>|string|null $rawTypes
     *
     * @return list<string>|null
     */
    private function parseTypesOption(array|string|null $rawTypes): ?array
    {
        if ($rawTypes === null || $rawTypes === []) {
            return null;
        }

        $values = [];
        if (is_string($rawTypes)) {
            $values[] = $rawTypes;
        } else {
            $values = $rawTypes;
        }

        $normalised = [];
        foreach ($values as $value) {
            foreach (explode(',', (string) $value) as $fragment) {
                $trimmed = trim($fragment);
                if ($trimmed === '') {
                    continue;
                }

                $normalised[] = [
                    'raw' => $trimmed,
                    'normalised' => strtolower($trimmed),
                ];
            }
        }

        if ($normalised === []) {
            return null;
        }

        $resolved = [];
        foreach ($normalised as $entry) {
            $candidate = $entry['normalised'];
            $resolvedGroup = $this->groupAliases[$candidate] ?? null;

            if ($resolvedGroup === null && in_array($candidate, $this->allowedGroups, true)) {
                $resolvedGroup = $candidate;
            }

            if ($resolvedGroup === null) {
                $friendlyNames = $this->friendlyNames === []
                    ? ''
                    : sprintf(' (Friendly Names: %s)', implode(', ', $this->friendlyNames));

                throw new InvalidArgumentException(sprintf(
                    'Unbekannter Erinnerungstyp "%s". Erlaubte Gruppen: %s%s.',
                    $entry['raw'],
                    implode(', ', $this->allowedGroups),
                    $friendlyNames,
                ));
            }

            $resolved[] = $resolvedGroup;
        }

        return array_values(array_unique($resolved));
    }

    private function parseDateOption(mixed $value, string $label): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Ungültiges Datum für --%s.', $label));
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d|', $value);
        if (!$date instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(sprintf('Ungültiges Datum für --%s. Erwartet wird YYYY-MM-DD.', $label));
        }

        return $date;
    }

    private function normaliseReindexStrategy(mixed $option): ?string
    {
        if ($option === null || $option === '') {
            return self::REINDEX_AUTO;
        }

        if (!is_string($option)) {
            return null;
        }

        $value = strtolower(trim($option));

        return match ($value) {
            self::REINDEX_AUTO, self::REINDEX_FORCE, self::REINDEX_SKIP => $value,
            default => null,
        };
    }

    private function runIndexingStage(SymfonyStyle $io, OutputInterface $output, string $mediaPath, string $strategy, bool $dryRun): void
    {
        $io->section('📥 Indexierung');

        if ($strategy === self::REINDEX_SKIP) {
            $io->text('Metadaten-Indexierung übersprungen.');

            return;
        }

        $force = $strategy === self::REINDEX_FORCE;

        $this->qaReportCollector->reset();
        $io->writeln(sprintf('Metadaten-Feature-Version: <info>%d</info>', MetadataFeatureVersion::CURRENT));
        $io->writeln($force ? '<comment>Neuindexierung wird erzwungen.</comment>' : '<comment>Nur veraltete Einträge werden aktualisiert.</comment>');
        if ($dryRun) {
            $io->note('Dry-Run: Änderungen werden nicht gespeichert.');
        }

        try {
            $files = iterator_to_array($this->fileLocator->locate($mediaPath, null, true));
        } catch (Throwable $exception) {
            $io->warning(sprintf('Dateisuche fehlgeschlagen: %s', $exception->getMessage()));

            return;
        }

        $total = count($files);
        if ($total === 0) {
            $io->warning('Keine passenden Mediendateien gefunden.');

            return;
        }

        $progress = new ProgressBar($output, $total);
        $progress->setFormat('%current%/%max% [%bar%] %percent:3s%% | Dauer: %elapsed:6s% | Datei: %filename%');
        $progress->setMessage('', 'filename');
        $progress->start();

        $processed = 0;
        $finalized = false;

        try {
            foreach ($files as $filepath) {
                $progress->setMessage($filepath, 'filename');

                try {
                    $this->pipeline->process(
                        $filepath,
                        $force,
                        $dryRun,
                        false,
                        false,
                        $output,
                    );
                } catch (Throwable $exception) {
                    $io->error(sprintf('Fehler bei %s: %s', $filepath, $exception->getMessage()));

                    if (!$dryRun) {
                        $this->pipeline->finalize($dryRun);
                        $finalized = true;

                        throw $exception;
                    }
                }

                ++$processed;
                $progress->advance();
            }

            if (!$finalized) {
                $this->pipeline->finalize($dryRun);
                $finalized = true;
            }

            $io->success(sprintf('%d Dateien verarbeitet.', $processed));
            $this->qaReportCollector->render($output);
        } finally {
            if (!$finalized) {
                $this->pipeline->finalize($dryRun);
            }

            $progress->finish();
            $io->newLine(2);
            $this->qaReportCollector->reset();
        }
    }

    private function runClusteringStage(
        SymfonyStyle $io,
        OutputInterface $output,
        bool $dryRun,
        ?DateTimeImmutable $since,
        ?DateTimeImmutable $until,
        ?array $allowedGroups,
    ): ?ClusterJobResult {
        $io->section('🧠 Clustering & Kuration');

        $options = new ClusterJobOptions(
            dryRun: $dryRun,
            limit: null,
            since: $since,
            replace: false,
            vacationDebug: false,
            until: $until,
            allowedGroups: $allowedGroups,
        );

        $progressReporter = new ConsoleProgressReporter($io, $output);

        try {
            $result = $this->clusterJobRunner->run($options, $progressReporter);
        } catch (Throwable $exception) {
            $io->error(sprintf('Clustering fehlgeschlagen: %s', $exception->getMessage()));

            return null;
        }

        if ($result->getTotalMediaCount() === 0) {
            $io->warning('Keine Medien in der Datenbank gefunden.');

            return $result;
        }

        if ($result->getLoadedMediaCount() === 0) {
            $io->warning('Keine Medien erfüllt die Datumsfilter.');

            return $result;
        }

        if ($result->getDraftCount() === 0) {
            $io->note('Keine Cluster-Kandidaten erzeugt.');

            return $result;
        }

        if ($result->getConsolidatedCount() === 0) {
            $io->note('Alle Cluster wurden während der Konsolidierung verworfen.');

            return $result;
        }

        $io->success(sprintf(
            '%d Medien verarbeitet → %d Cluster konsolidiert (%d gespeichert).',
            $result->getLoadedMediaCount(),
            $result->getConsolidatedCount(),
            $result->getPersistedCount(),
        ));

        if ($result->getDeletedCount() > 0) {
            $io->info(sprintf('%d veraltete Cluster entfernt.', $result->getDeletedCount()));
        }

        return $result;
    }

    private function runFeedExportStage(SymfonyStyle $io): int
    {
        $io->section('📰 Feed-Export');

        $request = new FeedExportRequest(
            limitClusters: 5000,
            maxItems: 60,
            imagesPerItem: 16,
            thumbnailWidth: 512,
            useSymlinks: false,
            baseOutputDirectory: 'var/export',
            timestamp: new DateTimeImmutable('now'),
            stage: FeedExportStage::Curated,
        );

        try {
            $result = $this->feedExportService->export($request, $io);
        } catch (Throwable $exception) {
            $io->error(sprintf('Feed-Export fehlgeschlagen: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        if ($result->hasIndexFile()) {
            $io->success(sprintf(
                'HTML erzeugt: %s | Bilder: %d kopiert/verlinkt, %d ohne Thumbnail. Elemente: %d',
                $result->getIndexFilePath(),
                $result->getCopiedFileCount(),
                $result->getSkippedNoThumbnailCount(),
                $result->getCardCount(),
            ));
        } else {
            $io->success('Feed-Export abgeschlossen (kein Index erzeugt).');
        }

        return Command::SUCCESS;
    }
}

