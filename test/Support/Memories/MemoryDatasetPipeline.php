<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Support\Memories;

use DateTimeImmutable;
use InvalidArgumentException;

use function array_diff;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function round;
use function sort;
use function sprintf;
use function strtolower;
use function trim;
use function usort;

final class MemoryDatasetPipeline
{
    /**
     * @return array<string, mixed>
     */
    public function run(MemoryDataset $dataset): array
    {
        $clusters = [];
        $clusterKeyPhotos = [];

        foreach ($dataset->getClusters() as $clusterIndex => $cluster) {
            $clusterId = $this->requireString($cluster, 'id', sprintf('Cluster %d is missing an id', $clusterIndex));
            $clusterTitle = $this->requireString($cluster, 'title', sprintf('Cluster "%s" requires a title.', $clusterId));
            $clusterSummary = $this->requireString($cluster, 'summary', sprintf('Cluster "%s" requires a summary.', $clusterId));

            $itemsRaw = $cluster['items'] ?? null;
            if (!is_array($itemsRaw) || $itemsRaw === []) {
                throw new InvalidArgumentException(sprintf('Cluster "%s" must declare at least one item.', $clusterId));
            }

            $items = [];
            foreach ($itemsRaw as $itemIndex => $item) {
                if (!is_array($item)) {
                    throw new InvalidArgumentException(sprintf('Cluster "%s" item %d must be an array.', $clusterId, $itemIndex));
                }

                $filename = $this->requireString($item, 'filename', sprintf('Cluster "%s" item %d requires a filename.', $clusterId, $itemIndex));
                $takenAt = $this->requireString($item, 'taken_at', sprintf('Cluster "%s" item %d requires a timestamp.', $clusterId, $itemIndex));

                try {
                    $takenAtValue = new DateTimeImmutable($takenAt);
                } catch (\Exception $exception) {
                    throw new InvalidArgumentException(sprintf('Cluster "%s" item %s has invalid timestamp "%s" (%s).', $clusterId, $filename, $takenAt, $exception->getMessage()));
                }

                $qualityRaw = $item['quality'] ?? 0;
                if (!is_numeric($qualityRaw)) {
                    throw new InvalidArgumentException(sprintf('Cluster "%s" item %s has invalid quality value.', $clusterId, $filename));
                }
                $quality = (float) $qualityRaw;

                $roles = $item['roles'] ?? [];
                if (!is_array($roles)) {
                    throw new InvalidArgumentException(sprintf('Cluster "%s" item %s roles must be an array.', $clusterId, $filename));
                }
                $roles = array_map(static fn ($value): string => strtolower(trim((string) $value)), $roles);

                $tags = $this->normaliseStringList($item['tags'] ?? [], sprintf('Cluster "%s" item %s tags must be strings.', $clusterId, $filename));
                $people = $this->normaliseStringList($item['people'] ?? [], sprintf('Cluster "%s" item %s people must be strings.', $clusterId, $filename));
                $storyLabel = $this->requireString($item, 'story_label', sprintf('Cluster "%s" item %s requires a story_label.', $clusterId, $filename));

                $locationRaw = $item['location'] ?? [];
                if (!is_array($locationRaw)) {
                    throw new InvalidArgumentException(sprintf('Cluster "%s" item %s location must be an array.', $clusterId, $filename));
                }

                $city = $this->requireString($locationRaw, 'city', sprintf('Cluster "%s" item %s location requires a city.', $clusterId, $filename));
                $country = $this->requireString($locationRaw, 'country', sprintf('Cluster "%s" item %s location requires a country.', $clusterId, $filename));

                $items[] = [
                    'filename' => $filename,
                    'taken_at' => $takenAtValue,
                    'quality' => $quality,
                    'roles' => $roles,
                    'tags' => $tags,
                    'people' => $people,
                    'story' => $storyLabel,
                    'location_label' => sprintf('%s (%s)', $city, $country),
                ];
            }

            usort($items, static fn (array $left, array $right): int => $left['taken_at'] <=> $right['taken_at']);

            $memberCount = count($items);
            $start = $items[0]['taken_at'];
            $end = $items[$memberCount - 1]['taken_at'];
            $coverageSeconds = max(0, $end->getTimestamp() - $start->getTimestamp());
            $coverageHours = $memberCount === 1 ? 0.0 : round($coverageSeconds / 3600, 2);

            $daysPresent = [];
            $storyBeats = [];
            $tags = [];
            $people = [];
            $locations = [];
            $highlights = [];

            $keyCandidate = null;
            foreach ($items as $item) {
                $day = $item['taken_at']->format('Y-m-d');
                if (!in_array($day, $daysPresent, true)) {
                    $daysPresent[] = $day;
                }

                if (!in_array($item['story'], $storyBeats, true)) {
                    $storyBeats[] = $item['story'];
                }

                $tags = array_merge($tags, $item['tags']);
                $people = array_merge($people, $item['people']);
                $locations[] = $item['location_label'];

                if (in_array('highlight', $item['roles'], true)) {
                    $highlights[] = $item['filename'];
                }

                $isKey = in_array('key', $item['roles'], true);
                if ($keyCandidate === null || ($isKey && !$keyCandidate['is_key']) || ($item['quality'] > $keyCandidate['quality'] && ($isKey || !$keyCandidate['is_key']))) {
                    $keyCandidate = [
                        'filename' => $item['filename'],
                        'quality' => $item['quality'],
                        'taken_at' => $item['taken_at'],
                        'is_key' => $isKey,
                    ];
                }
            }

            $tags = array_values(array_unique($tags));
            sort($tags);

            $people = array_values(array_unique($people));
            sort($people);

            $locations = array_values(array_unique($locations));
            sort($locations);

            $highlights = array_values(array_unique($highlights));

            $expectedDaysRaw = $cluster['expected_days'] ?? [];
            if (!is_array($expectedDaysRaw)) {
                throw new InvalidArgumentException(sprintf('Cluster "%s" expected_days must be an array.', $clusterId));
            }

            $expectedDays = array_map(static function ($value): string {
                if (!is_string($value) || $value === '') {
                    throw new InvalidArgumentException('Expected day entries must be non-empty strings.');
                }

                return $value;
            }, $expectedDaysRaw);
            sort($expectedDays);

            $missingDates = array_values(array_diff($expectedDays, $daysPresent));

            $clusterKeyPhotos[$clusterId] = $keyCandidate['filename'] ?? ($highlights[0] ?? $items[0]['filename']);

            $clusters[] = [
                'id' => $clusterId,
                'title' => $clusterTitle,
                'summary' => $clusterSummary,
                'member_count' => $memberCount,
                'timeline' => [
                    'start' => $start->format(DateTimeImmutable::ATOM),
                    'end' => $end->format(DateTimeImmutable::ATOM),
                    'coverage_hours' => $coverageHours,
                    'days_present' => $daysPresent,
                ],
                'missing_dates' => $missingDates,
                'key_photo' => $clusterKeyPhotos[$clusterId],
                'highlight_photos' => $highlights,
                'locations' => $locations,
                'tags' => $tags,
                'people' => $people,
                'story_beats' => $storyBeats,
            ];
        }

        $storyboard = [
            'cover_photo' => $clusterKeyPhotos[$dataset->getPrimaryClusterId()] ?? ($clusters[0]['key_photo'] ?? null),
            'themes' => $dataset->getThemes(),
            'transitions' => $dataset->getStoryboardTransitions(),
            'key_photos' => array_values($clusterKeyPhotos),
            'sequences' => array_map(static function (array $cluster): array {
                return [
                    'cluster' => $cluster['id'],
                    'title' => $cluster['title'],
                    'story_beats' => $cluster['story_beats'],
                ];
            }, $clusters),
        ];

        return [
            'dataset' => $dataset->getName(),
            'title' => $dataset->getTitle(),
            'clusters' => $clusters,
            'storyboard' => $storyboard,
        ];
    }

    /**
     * @param array<mixed> $source
     */
    private function requireString(array $source, string $key, string $errorMessage): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($errorMessage);
        }

        return $value;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<int, string>
     */
    private function normaliseStringList(array $values, string $errorMessage): array
    {
        $normalised = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException($errorMessage);
            }

            $normalised[] = trim($value);
        }

        return $normalised;
    }
}
