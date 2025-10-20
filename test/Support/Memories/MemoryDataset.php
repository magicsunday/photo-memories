<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Support\Memories;

final class MemoryDataset
{
    /**
     * @param array<int, string>                     $themes
     * @param array<int, string>                     $storyboardTransitions
     * @param array<int, array<string, mixed>>       $clusters
     * @param array<string, mixed>                   $expected
     */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly array $themes,
        private readonly string $primaryClusterId,
        private readonly array $storyboardTransitions,
        private readonly array $clusters,
        private readonly array $expected,
        private readonly string $datasetPath,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return array<int, string>
     */
    public function getThemes(): array
    {
        return $this->themes;
    }

    public function getPrimaryClusterId(): string
    {
        return $this->primaryClusterId;
    }

    /**
     * @return array<int, string>
     */
    public function getStoryboardTransitions(): array
    {
        return $this->storyboardTransitions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getClusters(): array
    {
        return $this->clusters;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExpected(): array
    {
        return $this->expected;
    }

    public function getDatasetPath(): string
    {
        return $this->datasetPath;
    }

    public function resolvePreviewPath(string $filename): string
    {
        return $this->datasetPath . '/' . ltrim($filename, '/');
    }
}
