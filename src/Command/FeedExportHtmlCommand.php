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
use MagicSunday\Memories\Service\Feed\Contract\FeedExportServiceInterface;
use MagicSunday\Memories\Service\Feed\FeedExportRequest;
use Throwable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function max;
use function sprintf;

/**
 * Export a static HTML page that previews the "Für dich" feed.
 * Copies (or symlinks) thumbnails into an export folder and uses lazy-loading.
 * Greift dabei auf die globale Algorithmus-Begrenzung des Feed-Builders zurück.
 */
#[AsCommand(
    name: 'memories:feed:export-html',
    description: 'Erzeugt eine HTML-Vorschau des Rückblick-Feeds (statisch, mit Lazy-Loading).'
)]
final class FeedExportHtmlCommand extends Command
{
    public function __construct(
        private readonly FeedExportServiceInterface $exportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit-clusters', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl geladener Cluster', '5000')
            ->addOption('max-items', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl Feed-Items in der Seite', '60')
            ->addOption('images-per-item', null, InputOption::VALUE_REQUIRED, 'Maximale Bilder pro Feed-Item', '16')
            ->addOption('thumb-width', null, InputOption::VALUE_REQUIRED, 'Gewünschte Thumbnail-Breite', '512')
            ->addOption('symlink', null, InputOption::VALUE_NONE, 'Anstatt zu kopieren werden Symlinks verwendet (sofern möglich)')
            ->addArgument('out-dir', InputArgument::OPTIONAL, 'Ausgabeverzeichnis', 'var/export')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $limitClusters = max(1, (int) $input->getOption('limit-clusters'));
        $maxItems      = max(1, (int) $input->getOption('max-items'));
        $imagesPerItem = max(1, (int) $input->getOption('images-per-item'));
        $thumbWidth    = max(64, (int) $input->getOption('thumb-width'));
        $useSymlink    = (bool) $input->getOption('symlink');
        $baseOutDir    = (string) $input->getArgument('out-dir');

        $request = new FeedExportRequest(
            limitClusters: $limitClusters,
            maxItems: $maxItems,
            imagesPerItem: $imagesPerItem,
            thumbnailWidth: $thumbWidth,
            useSymlinks: $useSymlink,
            baseOutputDirectory: $baseOutDir,
            timestamp: new DateTimeImmutable('now'),
        );

        try {
            $result = $this->exportService->export($request, $io);
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($result->hasIndexFile()) {
            $io->success(sprintf(
                "HTML erzeugt: %s\nBilder: %d kopiert/verlinkt, %d übersprungen (keine Thumbnail-Quelle).",
                $result->getIndexFilePath(),
                $result->getCopiedFileCount(),
                $result->getSkippedNoThumbnailCount(),
            ));
        }

        return Command::SUCCESS;
    }
}
