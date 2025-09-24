<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\MetadataExtractorInterface;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Index media files: extract metadata and persist to DB.
 *
 * - Uses a fast extension whitelist to collect files.
 * - MIME detection is centralized via a single \finfo instance (with safe fallback).
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
    /** @var string[] */
    private array $imageExt;

    /** @var string[] */
    private array $videoExt;

    public function __construct(
        private EntityManagerInterface $em,
        private MetadataExtractorInterface $metadataExtractor,
        private ThumbnailServiceInterface $thumbnailService,
        #[Autowire(env: 'MEMORIES_MEDIA_DIR')] private string $defaultMediaDir,
        #[Autowire(param: 'memories.index.image_ext')] ?array $imageExt = null,
        #[Autowire(param: 'memories.index.video_ext')] ?array $videoExt = null,
    ) {
        parent::__construct();

        // Sensible defaults if parameters are not provided via services.yaml
        $this->imageExt = $imageExt ?? [
            'jpg','jpeg','jpe','jxl','avif','heic','heif','png','webp','gif','bmp','tiff','tif',
            'cr2','cr3','nef','arw','rw2','raf','dng',
        ];
        $this->videoExt = $videoExt ?? [
            'mp4','m4v','mov','3gp','3g2','avi','mkv','webm',
        ];
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
        $path        = (string) $input->getArgument('path');
        $force       = (bool) $input->getOption('force');
        $dryRun      = (bool) $input->getOption('dry-run');
        $maxFiles    = $this->toIntOrNull($input->getOption('max-files'));
        $withThumbs  = (bool) $input->getOption('thumbnails');
        $noProgress  = (bool) $input->getOption('no-progress');
        $strictMime  = (bool) $input->getOption('strict-mime');

        if (!\is_dir($path)) {
            $output->writeln("<error>Pfad existiert nicht oder ist kein Verzeichnis: {$path}</error>");
            return Command::FAILURE;
        }

        $output->writeln("Starte Indexierung: <info>{$path}</info>");
        $output->writeln($withThumbs ? '<comment>Thumbnails werden erzeugt.</comment>' : '<comment>Thumbnails werden nicht erzeugt (Option --thumbnails verwenden).</comment>');
        if ($strictMime === true) {
            $output->writeln('<comment>Strikter MIME-Check ist aktiv.</comment>');
        }

        // Collect files using extension whitelist (fast)
        $files = $this->collectMediaFilesByExtension($path, $maxFiles);
        $total = \count($files);

        if ($total === 0) {
            $output->writeln('<comment>Keine passenden Dateien gefunden.</comment>');
            return Command::SUCCESS;
        }

        // Shared finfo instance for this run
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        // Optional progress bar
        $progress = null;
        if ($noProgress === false) {
            $progress = new ProgressBar($output, $total);
            $progress->setFormat('%current%/%max% [%bar%] %percent:3s%% | Dauer: %elapsed:6s% | ETA: %estimated:-6s% | Datei: %filename%');
            $progress->setMessage('', 'filename');
            $progress->start();
        }

        $count = 0;
        $batch = 0;

        foreach ($files as $filepath) {
            if ($progress !== null) {
                $progress->setMessage($filepath, 'filename');
            }

            // MIME detection (shared finfo + fallback)
            $detectedMime = $this->detectMime($filepath, $finfo);

            // If strict MIME is enabled, enforce consistency with extension
            if ($strictMime === true) {
                $isImage = $this->isImageExt($filepath);
                $isVideo = $this->isVideoExt($filepath);

                if ($isImage && \preg_match('#^image/#', $detectedMime) !== 1) {
                    if ($progress !== null) {
                        $progress->advance();
                    }
                    continue;
                }
                if ($isVideo && \preg_match('#^video/#', $detectedMime) !== 1) {
                    if ($progress !== null) {
                        $progress->advance();
                    }
                    continue;
                }
            }

            $output->writeln("Verarbeite: {$filepath}", OutputInterface::VERBOSITY_VERBOSE);

            $checksum = @\hash_file('sha256', $filepath);
            if ($checksum === false) {
                $output->writeln("<error>Could not compute checksum for file: {$filepath}</error>");
                if ($progress !== null) {
                    $progress->advance();
                }
                continue;
            }

            /** @var Media|null $existing */
            $existing = $this->em->getRepository(Media::class)->findOneBy(['checksum' => $checksum]);

            if ($existing !== null && $force === false) {
                $output->writeln(' -> Übersprungen (bereits indexiert)', OutputInterface::VERBOSITY_VERBOSE);
                if ($progress !== null) {
                    $progress->advance();
                }
                continue;
            }

            $size  = \filesize($filepath) ?: 0;
            $media = $existing ?? new Media($filepath, $checksum, $size);
            $media->setMime($detectedMime);

            // Extract metadata (EXIF/ffprobe) – no raw JSON is persisted
            try {
                $media = $this->metadataExtractor->extract($filepath, $media);
            } catch (\Throwable $e) {
                $output->writeln("<error>Metadata extraction failed for {$filepath}: {$e->getMessage()}</error>");
            }

            // Thumbnails only when requested
            if ($withThumbs === true) {
                try {
                    $thumbnails = $this->thumbnailService->generateAll($filepath, $media);
                    $media->setThumbnails($thumbnails);
                } catch (\Throwable $e) {
                    $output->writeln("<error>Thumbnail generation failed for {$filepath}: {$e->getMessage()}</error>");
                }
            }

            if ($dryRun === false) {
                $this->em->persist($media);
                $batch++;

                if ($batch >= 50) {
                    $this->em->flush();
                    $this->em->clear();
                    $batch = 0;
                }
            } else {
                $output->writeln(' (dry-run) ', OutputInterface::VERBOSITY_VERBOSE);
            }

            $count++;
            if ($progress !== null) {
                $progress->advance();
            }
        }

        if ($dryRun === false) {
            $this->em->flush();
        }

        if ($progress !== null) {
            $progress->finish();
            $output->writeln('');
        }

        $output->writeln("<info>Indexierung abgeschlossen. Insgesamt verarbeitete Dateien: {$count}</info>");
        return Command::SUCCESS;
    }

    /**
     * Collect image/video files from a base directory using a fast extension whitelist.
     *
     * @return list<string>
     */
    private function collectMediaFilesByExtension(string $baseDir, ?int $maxFiles): array
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        $out = [];
        foreach ($rii as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $path = $fileInfo->getPathname();
            if ($this->isSupportedByExtension($path) === false) {
                continue;
            }
            $out[] = $path;
            if ($maxFiles !== null && \count($out) >= $maxFiles) {
                break;
            }
        }
        return $out;
    }

    private function isSupportedByExtension(string $path): bool
    {
        return $this->isImageExt($path) || $this->isVideoExt($path);
    }

    private function isImageExt(string $path): bool
    {
        $ext = \strtolower((string) \pathinfo($path, PATHINFO_EXTENSION));
        return $ext !== '' && \in_array($ext, $this->imageExt, true);
    }

    private function isVideoExt(string $path): bool
    {
        $ext = \strtolower((string) \pathinfo($path, PATHINFO_EXTENSION));
        return $ext !== '' && \in_array($ext, $this->videoExt, true);
    }

    private function toIntOrNull(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }
        if (\is_numeric($v)) {
            return (int) $v;
        }
        return null;
    }

    /**
     * Determine MIME type using a shared finfo instance with a safe fallback.
     */
    private function detectMime(string $path, \finfo $finfo): string
    {
        $mime = '';
        try {
            $m = @$finfo->file($path);
            if (\is_string($m) && $m !== '') {
                $mime = $m;
            }
        } catch (\Throwable) {
            // ignore and try fallback
        }

        if ($mime === '') {
            $m = @\mime_content_type($path);
            if (\is_string($m) && $m !== '') {
                $mime = $m;
            }
        }

        return $mime !== '' ? $mime : 'application/octet-stream';
    }
}
