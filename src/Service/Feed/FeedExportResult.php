<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

/**
 * Class FeedExportResult.
 */
final readonly class FeedExportResult
{
    public function __construct(
        private string $outputDirectory,
        private string $imageDirectory,
        private ?string $indexFilePath,
        private int $copiedFileCount,
        private int $skippedNoThumbnailCount,
        private int $cardCount,
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
