<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing\Contract;

use MagicSunday\Memories\Entity\Media;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class MediaIngestionContext
 */
final readonly class MediaIngestionContext
{
    private function __construct(
        private string          $filePath,
        private bool            $force,
        private bool            $dryRun,
        private bool            $withThumbnails,
        private bool            $strictMime,
        private OutputInterface $output,
        private ?Media          $media,
        private ?string         $detectedMime,
        private bool            $detectedRaw,
        private bool            $detectedHeic,
        private bool            $detectedHevc,
        private ?string         $checksum,
        private bool            $reindexRequired,
        private bool            $skipped,
        private ?string         $skipMessage,
    ) {
    }

    public static function create(
        string $filePath,
        bool $force,
        bool $dryRun,
        bool $withThumbnails,
        bool $strictMime,
        OutputInterface $output
    ): self {
        return new self(
            $filePath,
            $force,
            $dryRun,
            $withThumbnails,
            $strictMime,
            $output,
            null,
            null,
            false,
            false,
            false,
            null,
            false,
            false,
            null,
        );
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function isForce(): bool
    {
        return $this->force;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function shouldGenerateThumbnails(): bool
    {
        return $this->withThumbnails;
    }

    public function isStrictMime(): bool
    {
        return $this->strictMime;
    }

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    public function getMedia(): ?Media
    {
        return $this->media;
    }

    public function getDetectedMime(): ?string
    {
        return $this->detectedMime;
    }

    public function isDetectedRaw(): bool
    {
        return $this->detectedRaw;
    }

    public function isDetectedHeic(): bool
    {
        return $this->detectedHeic;
    }

    public function isDetectedHevc(): bool
    {
        return $this->detectedHevc;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function requiresReindex(): bool
    {
        return $this->reindexRequired;
    }

    public function getSkipMessage(): ?string
    {
        return $this->skipMessage;
    }

    public function withDetectedMime(
        string $detectedMime,
        bool $isRaw = false,
        bool $isHeic = false,
        bool $isHevc = false,
    ): self {
        return new self(
            $this->filePath,
            $this->force,
            $this->dryRun,
            $this->withThumbnails,
            $this->strictMime,
            $this->output,
            $this->media,
            $detectedMime,
            $isRaw,
            $isHeic,
            $isHevc,
            $this->checksum,
            $this->reindexRequired,
            $this->skipped,
            $this->skipMessage,
        );
    }

    public function withChecksum(string $checksum): self
    {
        return new self(
            $this->filePath,
            $this->force,
            $this->dryRun,
            $this->withThumbnails,
            $this->strictMime,
            $this->output,
            $this->media,
            $this->detectedMime,
            $this->detectedRaw,
            $this->detectedHeic,
            $this->detectedHevc,
            $checksum,
            $this->reindexRequired,
            $this->skipped,
            $this->skipMessage,
        );
    }

    public function withMedia(Media $media): self
    {
        return new self(
            $this->filePath,
            $this->force,
            $this->dryRun,
            $this->withThumbnails,
            $this->strictMime,
            $this->output,
            $media,
            $this->detectedMime,
            $this->detectedRaw,
            $this->detectedHeic,
            $this->detectedHevc,
            $this->checksum,
            $this->reindexRequired,
            $this->skipped,
            $this->skipMessage,
        );
    }

    public function withReindexRequired(bool $reindexRequired = true): self
    {
        return new self(
            $this->filePath,
            $this->force,
            $this->dryRun,
            $this->withThumbnails,
            $this->strictMime,
            $this->output,
            $this->media,
            $this->detectedMime,
            $this->detectedRaw,
            $this->detectedHeic,
            $this->detectedHevc,
            $this->checksum,
            $reindexRequired,
            $this->skipped,
            $this->skipMessage,
        );
    }

    public function markSkipped(?string $message = null): self
    {
        return new self(
            $this->filePath,
            $this->force,
            $this->dryRun,
            $this->withThumbnails,
            $this->strictMime,
            $this->output,
            $this->media,
            $this->detectedMime,
            $this->detectedRaw,
            $this->detectedHeic,
            $this->detectedHevc,
            $this->checksum,
            $this->reindexRequired,
            true,
            $message,
        );
    }
}
