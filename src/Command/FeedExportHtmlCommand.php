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
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Cluster as ClusterEntity;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidatorInterface;
use MagicSunday\Memories\Service\Feed\FeedBuilderInterface;
use MagicSunday\Memories\Service\Feed\HtmlFeedRenderer;
use MagicSunday\Memories\Service\Feed\ThumbnailPathResolver;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_slice;
use function basename;
use function copy;
use function count;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_string;
use function max;
use function sprintf;
use function symlink;
use function usort;

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
        private readonly EntityManagerInterface $em,
        private readonly FeedBuilderInterface $feedBuilder,
        private readonly ClusterConsolidatorInterface $consolidation,
        private readonly ClusterEntityToDraftMapper $mapper,
        private readonly MediaRepository $mediaRepo,
        private readonly HtmlFeedRenderer $renderer,
        private readonly ThumbnailPathResolver $thumbResolver,
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

        $stamp  = (new DateTimeImmutable('now'))->format('Ymd-His');
        $outDir = rtrim($baseOutDir, '/') . ('/feed-' . $stamp);
        $imgDir = $outDir . '/images';

        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            throw new RuntimeException('Could not create output directory: ' . $outDir);
        }

        if (!is_dir($imgDir) && !@mkdir($imgDir, 0775, true) && !is_dir($imgDir)) {
            throw new RuntimeException('Could not create images directory: ' . $imgDir);
        }

        $io->title('📰 HTML-Vorschau des Rückblick-Feeds');

        // 1) Load clusters from DB
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(ClusterEntity::class, 'c')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limitClusters);

        /** @var list<ClusterEntity> $entities */
        $entities = $qb->getQuery()->getResult();

        if ($entities === []) {
            $io->warning('Keine Cluster in der Datenbank gefunden.');

            return Command::SUCCESS;
        }

        // 2) Map & consolidate
        $drafts       = $this->mapper->mapMany($entities);
        $consolidated = $this->consolidation->consolidate($drafts);

        if ($consolidated === []) {
            $io->warning('Keine Cluster nach der Konsolidierung.');

            return Command::SUCCESS;
        }

        // 3) Build feed
        $items = $this->feedBuilder->build($consolidated);
        if ($items === []) {
            $io->warning('Der Feed ist leer (Filter/Score/Limit zu streng?).');

            return Command::SUCCESS;
        }

        // Truncate to max-items
        if (count($items) > $maxItems) {
            $items = array_slice($items, 0, $maxItems);
        }

        // 4) Prepare cards: copy/symlink thumbnails and create relative hrefs
        /** @var list<array{
         *   title:string, subtitle:string, algorithm:string, group?:string, score:float,
         *   images:list<array{href:string, alt:string}>
         * }> $cards */
        $cards = [];

        $copied         = 0;
        $skippedNoThumb = 0;

        foreach ($items as $it) {
            $memberIds = $it->getMemberIds();
            if ($memberIds === []) {
                continue;
            }

            /** @var list<Media> $members */
            $members = $this->mediaRepo->findByIds($memberIds);

            // Cover zuerst, dann weitere Bilder (stabilere Reihenfolge)
            $coverId = $it->getCoverMediaId();
            if ($coverId !== null) {
                usort($members, static function (Media $a, Media $b) use ($coverId): int {
                    if ($a->getId() === $coverId && $b->getId() !== $coverId) {
                        return -1;
                    }

                    if ($b->getId() === $coverId && $a->getId() !== $coverId) {
                        return 1;
                    }

                    $ta = $a->getTakenAt()?->getTimestamp() ?? 0;
                    $tb = $b->getTakenAt()?->getTimestamp() ?? 0;

                    return $ta <=> $tb;
                });
            }

            $images = [];
            foreach ($members as $m) {
                if (count($images) >= $imagesPerItem) {
                    break;
                }

                $src = $this->thumbResolver->resolveBest($m, $thumbWidth);
                if ($src === null) {
                    ++$skippedNoThumb;
                    continue;
                }

                $targetName = $this->thumbResolver->exportName($m, $src);
                $targetPath = $imgDir . '/' . $targetName;
                $relHref    = 'images/' . $targetName;

                if (!is_file($targetPath)) {
                    $ok = false;
                    if ($useSymlink) {
                        // Symlink best effort; fallback to copy on failure
                        $ok = @symlink($src, $targetPath);
                    }

                    if (!$ok) {
                        $ok = @copy($src, $targetPath);
                    }

                    if ($ok) {
                        ++$copied;
                    }
                }

                $alt      = basename($m->getPath());
                $images[] = ['href' => $relHref, 'alt' => $alt];
            }

            if ($images === []) {
                continue;
            }

            $params = $it->getParams();
            $group  = $params['group'] ?? null;

            $card = [
                'title'     => $it->getTitle(),
                'subtitle'  => $it->getSubtitle(),
                'algorithm' => $it->getAlgorithm(),
                'score'     => $it->getScore(),
                'images'    => $images,
            ];

            if (is_string($group) && $group !== '') {
                $card['group'] = $group;
            }

            $cards[] = $card;
        }

        if ($cards === []) {
            $io->warning('Keine Bilder für die HTML-Ausgabe gefunden.');

            return Command::SUCCESS;
        }

        // 5) Render HTML and write file
        $html = $this->renderer->render($cards, 'Rückblick – Für dich');

        $indexFile = $outDir . '/index.html';
        if (@file_put_contents($indexFile, $html) === false) {
            throw new RuntimeException('Konnte HTML-Datei nicht schreiben: ' . $indexFile);
        }

        $io->success(sprintf(
            "HTML erzeugt: %s\nBilder: %d kopiert/verlinkt, %d übersprungen (keine Thumbnail-Quelle).",
            $indexFile,
            $copied,
            $skippedNoThumb
        ));

        return Command::SUCCESS;
    }
}
