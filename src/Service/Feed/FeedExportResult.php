<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

final class FeedExportResult
{
    public function __construct(
        private readonly string $outputDirectory,
        private readonly string $imageDirectory,
        private readonly ?string $indexFilePath,
        private readonly int $copiedFileCount,
        private readonly int $skippedNoThumbnailCount,
        private readonly int $cardCount,
    ) {
    }

    public function getOutputDirectory(): string
    {
        return $this->outputDirectory;
    }

    public function getImageDirectory(): string
    {
        return $this->imageDirectory;
    }

    public function getIndexFilePath(): ?string
    {
        return $this->indexFilePath;
    }

    public function hasIndexFile(): bool
    {
        return $this->indexFilePath !== null;
    }

    public function getCopiedFileCount(): int
    {
        return $this->copiedFileCount;
    }

    public function getSkippedNoThumbnailCount(): int
    {
        return $this->skippedNoThumbnailCount;
    }

    public function getCardCount(): int
    {
        return $this->cardCount;
    }
}
