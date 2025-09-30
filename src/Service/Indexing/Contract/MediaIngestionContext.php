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

final class MediaIngestionContext
{
    private function __construct(
        private readonly string $filePath,
        private readonly bool $force,
        private readonly bool $dryRun,
        private readonly bool $withThumbnails,
        private readonly bool $strictMime,
        private readonly OutputInterface $output,
        private readonly ?Media $media,
        private readonly ?string $detectedMime,
        private readonly ?string $checksum,
        private readonly bool $skipped,
        private readonly ?string $skipMessage,
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
            null,
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

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function getSkipMessage(): ?string
    {
        return $this->skipMessage;
    }

    public function withDetectedMime(string $detectedMime): self
    {
        return new self(
            $this->filePath,
            $this->force,
            $this->dryRun,
            $this->withThumbnails,
            $this->strictMime,
            $this->output,
            $this->media,
            $detectedMime,
            $this->checksum,
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
            $checksum,
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
            $this->checksum,
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
            $this->checksum,
            true,
            $message,
        );
    }
}
