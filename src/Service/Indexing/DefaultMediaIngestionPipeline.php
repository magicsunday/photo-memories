<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing;

use Doctrine\ORM\EntityManagerInterface;
use finfo;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\MetadataExtractorInterface;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailServiceInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function filesize;
use function hash_file;
use function in_array;
use function is_string;
use function mime_content_type;
use function pathinfo;
use function preg_match;
use function sprintf;
use function strtolower;

use const FILEINFO_MIME_TYPE;
use const PATHINFO_EXTENSION;

final class DefaultMediaIngestionPipeline implements MediaIngestionPipelineInterface
{
    private const BATCH_SIZE = 50;

    private readonly finfo $finfo;

    private int $batchCount = 0;

    /**
     * @var list<string>
     */
    private readonly array $imageExtensions;

    /**
     * @var list<string>
     */
    private readonly array $videoExtensions;

    private const DEFAULT_IMAGE_EXT = [
        'jpg', 'jpeg', 'jpe', 'jxl', 'avif', 'heic', 'heif', 'png', 'webp', 'gif', 'bmp', 'tiff', 'tif',
        'cr2', 'cr3', 'nef', 'arw', 'rw2', 'raf', 'dng',
    ];

    private const DEFAULT_VIDEO_EXT = [
        'mp4', 'm4v', 'mov', '3gp', '3g2', 'avi', 'mkv', 'webm',
    ];

    /**
     * @param list<string>|null $imageExtensions
     * @param list<string>|null $videoExtensions
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MetadataExtractorInterface $metadataExtractor,
        private readonly ThumbnailServiceInterface $thumbnailService,
        ?array $imageExtensions = null,
        ?array $videoExtensions = null,
    ) {
        $this->imageExtensions = $imageExtensions ?? self::DEFAULT_IMAGE_EXT;
        $this->videoExtensions = $videoExtensions ?? self::DEFAULT_VIDEO_EXT;
        $this->finfo          = new finfo(FILEINFO_MIME_TYPE);
    }

    public function process(
        string $filepath,
        bool $force,
        bool $dryRun,
        bool $withThumbnails,
        bool $strictMime,
        OutputInterface $output
    ): ?Media {
        $detectedMime = $this->detectMime($filepath);

        if ($strictMime && $this->isMimeConsistent($filepath, $detectedMime) === false) {
            return null;
        }

        $checksum = @hash_file('sha256', $filepath);
        if ($checksum === false) {
            $output->writeln(sprintf('<error>Could not compute checksum for file: %s</error>', $filepath));

            return null;
        }

        $repository = $this->entityManager->getRepository(Media::class);
        $existing   = $repository->findOneBy(['checksum' => $checksum]);

        if ($existing instanceof Media && $force === false) {
            $output->writeln(' -> Übersprungen (bereits indexiert)', OutputInterface::VERBOSITY_VERBOSE);

            return null;
        }

        $size  = filesize($filepath) ?: 0;
        $media = $existing ?? new Media($filepath, $checksum, $size);
        $media->setMime($detectedMime);

        try {
            $media = $this->metadataExtractor->extract($filepath, $media);
        } catch (Throwable $exception) {
            $output->writeln(
                sprintf('<error>Metadata extraction failed for %s: %s</error>', $filepath, $exception->getMessage())
            );
        }

        if ($withThumbnails) {
            try {
                $media->setThumbnails($this->thumbnailService->generateAll($filepath, $media));
            } catch (Throwable $exception) {
                $output->writeln(
                    sprintf('<error>Thumbnail generation failed for %s: %s</error>', $filepath, $exception->getMessage())
                );
            }
        }

        if ($dryRun) {
            $output->writeln(' (dry-run) ', OutputInterface::VERBOSITY_VERBOSE);

            return $media;
        }

        $this->entityManager->persist($media);
        ++$this->batchCount;

        if ($this->batchCount >= self::BATCH_SIZE) {
            $this->entityManager->flush();
            $this->entityManager->clear();
            $this->batchCount = 0;
        }

        return $media;
    }

    public function finalize(bool $dryRun): void
    {
        if ($dryRun) {
            $this->batchCount = 0;

            return;
        }

        $this->entityManager->flush();
        $this->batchCount = 0;
    }

    private function detectMime(string $path): string
    {
        $mime = '';

        try {
            $m = @$this->finfo->file($path);
            if (is_string($m) && $m !== '') {
                $mime = $m;
            }
        } catch (Throwable) {
            // ignore and try fallback
        }

        if ($mime === '') {
            $m = @mime_content_type($path);
            if (is_string($m) && $m !== '') {
                $mime = $m;
            }
        }

        return $mime !== '' ? $mime : 'application/octet-stream';
    }

    private function isMimeConsistent(string $filepath, string $detectedMime): bool
    {
        if ($this->isImageExt($filepath)) {
            return preg_match('#^image/#', $detectedMime) === 1;
        }

        if ($this->isVideoExt($filepath)) {
            return preg_match('#^video/#', $detectedMime) === 1;
        }

        return true;
    }

    private function isImageExt(string $filepath): bool
    {
        $ext = strtolower((string) pathinfo($filepath, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->imageExtensions, true);
    }

    private function isVideoExt(string $filepath): bool
    {
        $ext = strtolower((string) pathinfo($filepath, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->videoExtensions, true);
    }
}
