<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use DateTimeImmutable;

use function max;

use const DIRECTORY_SEPARATOR;

/**
 * Class FeedExportRequest.
 */
final readonly class FeedExportRequest
{
    private int $limitClusters;

    private int $maxItems;

    private int $imagesPerItem;

    private int $thumbnailWidth;

    private string $baseOutputDirectory;

    public function __construct(
        int $limitClusters,
        int $maxItems,
        int $imagesPerItem,
        int $thumbnailWidth,
        private bool $useSymlinks,
        string $baseOutputDirectory,
        private DateTimeImmutable $timestamp,
        private FeedExportStage $stage,
    ) {
        $this->limitClusters       = max(1, $limitClusters);
        $this->maxItems            = max(1, $maxItems);
        $this->imagesPerItem       = max(1, $imagesPerItem);
        $this->thumbnailWidth      = max(1, $thumbnailWidth);
        $this->baseOutputDirectory = $baseOutputDirectory !== '' ? $baseOutputDirectory : 'var/export';
    }

    public function getLimitClusters(): int
    {
        return $this->limitClusters;
    }

    public function getMaxItems(): int
    {
        return $this->maxItems;
    }

    public function getImagesPerItem(): int
    {
        return $this->imagesPerItem;
    }

    public function getThumbnailWidth(): int
    {
        return $this->thumbnailWidth;
    }

    public function useSymlinks(): bool
    {
        return $this->useSymlinks;
    }

    public function getBaseOutputDirectory(): string
    {
        return $this->baseOutputDirectory;
    }

    public function getTimestamp(): DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function getStage(): FeedExportStage
    {
        return $this->stage;
    }

    public function resolveOutputDirectory(): string
    {
        $base = rtrim($this->baseOutputDirectory, '/\\');

        if ($base === '') {
            $base = '.';
        }

        return $base . DIRECTORY_SEPARATOR . 'feed-' . $this->timestamp->format('Ymd-His');
    }

    public function resolveImageDirectory(): string
    {
        return $this->resolveOutputDirectory() . DIRECTORY_SEPARATOR . 'images';
    }
}
