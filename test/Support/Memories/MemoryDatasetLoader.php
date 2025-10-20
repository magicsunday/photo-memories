<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Support\Memories;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

use function array_filter;
use function array_map;
use function array_values;
use function file_exists;
use function file_get_contents;
use function is_dir;
use function is_string;
use function json_decode;
use function ltrim;
use function rtrim;
use function scandir;
use function sprintf;
use function str_starts_with;
use function usort;

use const JSON_THROW_ON_ERROR;

final class MemoryDatasetLoader
{
    public function __construct(private readonly string $fixturesBaseDir)
    {
    }

    /**
     * @return array<int, string>
     */
    public function availableDatasets(): array
    {
        if (!is_dir($this->fixturesBaseDir)) {
            return [];
        }

        $entries = array_values(array_filter(scandir($this->fixturesBaseDir) ?: [], static fn (string $entry): bool => !str_starts_with($entry, '.')));
        usort($entries, static fn (string $left, string $right): int => $left <=> $right);

        return $entries;
    }

    public function load(string $dataset): MemoryDataset
    {
        $datasetPath = rtrim($this->fixturesBaseDir, '/').'/'.$dataset;
        if (!is_dir($datasetPath)) {
            throw new InvalidArgumentException(sprintf('Dataset "%s" does not exist under "%s".', $dataset, $this->fixturesBaseDir));
        }

        $metadataPath = $datasetPath.'/metadata.json';
        if (!file_exists($metadataPath)) {
            throw new InvalidArgumentException(sprintf('Dataset "%s" is missing metadata.json.', $dataset));
        }

        $metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($metadata)) {
            throw new RuntimeException(sprintf('Metadata for "%s" must decode to an array.', $dataset));
        }

        $name = $metadata['dataset'] ?? $dataset;
        if (!is_string($name) || $name === '') {
            throw new InvalidArgumentException(sprintf('Dataset name for "%s" must be a non-empty string.', $dataset));
        }

        $title = $metadata['title'] ?? '';
        if (!is_string($title) || $title === '') {
            throw new InvalidArgumentException(sprintf('Dataset "%s" requires a title.', $dataset));
        }

        $themes = $metadata['themes'] ?? [];
        if (!is_array($themes) || $themes === []) {
            throw new InvalidArgumentException(sprintf('Dataset "%s" must declare at least one theme.', $dataset));
        }

        $themes = array_map(static function ($value): string {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException('Theme entries must be non-empty strings.');
            }

            return $value;
        }, $themes);

        $primaryCluster = $metadata['primary_cluster'] ?? null;
        if (!is_string($primaryCluster) || $primaryCluster === '') {
            throw new InvalidArgumentException(sprintf('Dataset "%s" must define a primary_cluster.', $dataset));
        }

        $storyboard = $metadata['storyboard'] ?? [];
        $transitions = $storyboard['transitions'] ?? [];
        if (!is_array($transitions)) {
            throw new InvalidArgumentException(sprintf('Dataset "%s" storyboard transitions must be an array.', $dataset));
        }

        $transitions = array_map(static function ($value): string {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException('Storyboard transitions must be non-empty strings.');
            }

            return $value;
        }, $transitions);

        $clusters = $metadata['clusters'] ?? [];
        if (!is_array($clusters) || $clusters === []) {
            throw new InvalidArgumentException(sprintf('Dataset "%s" must contain at least one cluster.', $dataset));
        }

        foreach ($clusters as $cluster) {
            if (!is_array($cluster)) {
                throw new InvalidArgumentException(sprintf('Dataset "%s" cluster entries must be arrays.', $dataset));
            }

            $items = $cluster['items'] ?? [];
            if (!is_array($items) || $items === []) {
                throw new InvalidArgumentException(sprintf('Dataset "%s" cluster "%s" must include at least one item.', $dataset, (string) ($cluster['id'] ?? '?')));
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new InvalidArgumentException(sprintf('Dataset "%s" cluster "%s" items must be arrays.', $dataset, (string) ($cluster['id'] ?? '?')));
                }

                $preview = $item['preview'] ?? $item['filename'] ?? null;
                if (!is_string($preview) || $preview === '') {
                    throw new InvalidArgumentException(sprintf('Dataset "%s" cluster "%s" item is missing a preview reference.', $dataset, (string) ($cluster['id'] ?? '?')));
                }

                $previewPath = $datasetPath.'/'.ltrim($preview, '/');
                if (!file_exists($previewPath)) {
                    throw new InvalidArgumentException(sprintf('Preview "%s" referenced by dataset "%s" does not exist.', $preview, $dataset));
                }
            }
        }

        $expectedPath = $datasetPath.'/expected.yaml';
        if (!file_exists($expectedPath)) {
            throw new InvalidArgumentException(sprintf('Dataset "%s" is missing expected.yaml.', $dataset));
        }

        $expected = Yaml::parseFile($expectedPath);
        if (!is_array($expected)) {
            throw new RuntimeException(sprintf('Expected YAML for "%s" must parse to an array.', $dataset));
        }

        return new MemoryDataset(
            $name,
            $title,
            $themes,
            $primaryCluster,
            $transitions,
            $clusters,
            $expected,
            $datasetPath,
        );
    }
}
