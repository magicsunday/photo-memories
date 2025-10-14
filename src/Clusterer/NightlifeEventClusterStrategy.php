<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function array_any;
use function array_values;
use function assert;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function str_contains;
use function strtolower;
use function usort;

/**
 * Clusters evening/night sessions (20:00–04:00 local time) with time gap and spatial compactness.
 */
final readonly class NightlifeEventClusterStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use ProgressAwareClusterTrait;

    private ClusterQualityAggregator $qualityAggregator;

    public function __construct(
        private LocalTimeHelper $localTimeHelper,
        private LocationHelper $locationHelper,
        private int $timeGapSeconds = 3 * 3600, // 3h
        private float $radiusMeters = 300.0,
        private int $minItemsPerRun = 5,
        ?ClusterQualityAggregator $qualityAggregator = null,
    ) {
        if ($this->timeGapSeconds < 1) {
            throw new InvalidArgumentException('timeGapSeconds must be >= 1.');
        }

        if ($this->radiusMeters <= 0.0) {
            throw new InvalidArgumentException('radiusMeters must be > 0.');
        }

        if ($this->minItemsPerRun < 1) {
            throw new InvalidArgumentException('minItemsPerRun must be >= 1.');
        }

        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
    }

    public function name(): string
    {
        return 'nightlife_event';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $night = $this->filterTimestampedItemsBy(
            $items,
            function (Media $m): bool {
                $local = $this->localTimeHelper->resolve($m);
                assert($local instanceof DateTimeImmutable);
                $h = (int) $local->format('G');

                return ($h >= 20) || ($h <= 4);
            }
        );

        if (count($night) < $this->minItemsPerRun) {
            return [];
        }

        usort($night, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        /** @var list<list<Media>> $runs */
        $runs = [];
        /** @var list<Media> $buf */
        $buf    = [];
        $lastTs = null;

        foreach ($night as $m) {
            $ts = $m->getTakenAt()->getTimestamp();
            if ($lastTs !== null && ($ts - $lastTs) > $this->timeGapSeconds && $buf !== []) {
                $runs[] = $buf;
                $buf    = [];
            }

            $buf[]  = $m;
            $lastTs = $ts;
        }

        if ($buf !== []) {
            $runs[] = $buf;
        }

        $eligibleRuns = $this->filterListsByMinItems($runs, $this->minItemsPerRun);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleRuns as $run) {
            $gps      = $this->filterGpsItems($run);
            $centroid = $gps !== []
                ? MediaMath::centroid($gps)
                : ['lat' => 0.0, 'lon' => 0.0];

            $ok = true;
            foreach ($gps as $m) {
                $lat = $m->getGpsLat();
                $lon = $m->getGpsLon();
                assert($lat !== null && $lon !== null);

                $dist = MediaMath::haversineDistanceInMeters(
                    $centroid['lat'],
                    $centroid['lon'],
                    $lat,
                    $lon
                );

                if ($dist > $this->radiusMeters) {
                    $ok = false;
                    break;
                }
            }

            if (!$ok) {
                continue;
            }

            $time      = MediaMath::timeRange($run);
            $hasNight  = $this->hasNightDaypart($run);
            $sceneTags = $this->collectSceneTags($run);
            $poi       = $this->resolveNightlifePoi($run);

            if ($hasNight === false && $sceneTags === [] && $poi === null) {
                continue;
            }

            $params = [
                'time_range' => $time,
            ];

            $peopleParams = $this->buildPeopleParams($run);
            $params       = [...$params, ...$peopleParams];

            if ($hasNight === true) {
                $params['feature_daypart'] = 'night';
            }

            if ($sceneTags !== []) {
                $existingSceneTags = [];
                if (isset($params['scene_tags']) && is_array($params['scene_tags'])) {
                    $existingSceneTags = $this->sanitizeSceneTagList($params['scene_tags']);
                }

                $params['scene_tags'] = $this->mergeSceneTagEntries($existingSceneTags, $sceneTags);
            }

            if ($poi !== null) {
                $params['poi_label'] = $poi['label'];

                if ($poi['categoryKey'] !== null) {
                    $params['poi_category_key'] = $poi['categoryKey'];
                }

                if ($poi['categoryValue'] !== null) {
                    $params['poi_category_value'] = $poi['categoryValue'];
                }

                if ($poi['tags'] !== []) {
                    $params['poi_tags'] = $poi['tags'];
                }
            }

            $tagMetadata = $this->collectDominantTags($run);
            if ($tagMetadata !== []) {
                $params = [...$params, ...$tagMetadata];
            }

            $qualityParams = $this->qualityAggregator->buildParams($run);
            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $params = $this->appendLocationMetadata($run, $params);

            $out[] = new ClusterDraft(
                algorithm: 'nightlife_event',
                params: $params,
                centroid: ['lat' => $centroid['lat'], 'lon' => $centroid['lon']],
                members: $this->toMemberIds($run)
            );
        }

        return $out;
    }

    /**
     * @param list<Media> $run
     */
    private function hasNightDaypart(array $run): bool
    {
        return array_any(
            $run,
            static function (Media $media): bool {
                $daypart = $media->getFeatureBag()->calendarDaypart();
                if ($daypart === null) {
                    return false;
                }

                return strtolower($daypart) === 'night';
            }
        );
    }

    /**
     * @param list<Media> $run
     *
     * @return list<array{label: string, score: float}>
     */
    private function collectSceneTags(array $run): array
    {
        $keywords  = ['night', 'club', 'party', 'concert', 'festival', 'bar', 'pub', 'dj', 'dance'];
        $collected = [];

        foreach ($run as $media) {
            $tags = $media->getSceneTags();
            if (!is_array($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                if (!is_array($tag)) {
                    continue;
                }

                $label = $tag['label'] ?? null;
                $score = $tag['score'] ?? null;

                if (!is_string($label)) {
                    continue;
                }

                if (!is_float($score) && !is_int($score)) {
                    continue;
                }

                $normalized = strtolower($label);
                $matches    = false;
                foreach ($keywords as $keyword) {
                    if (str_contains($normalized, $keyword)) {
                        $matches = true;
                        break;
                    }
                }

                if ($matches === false) {
                    continue;
                }

                if (!isset($collected[$label]) || $collected[$label]['score'] < (float) $score) {
                    $collected[$label] = ['label' => $label, 'score' => (float) $score];
                }
            }
        }

        return array_values($collected);
    }

    /**
     * @param list<Media> $run
     *
     * @return array{label: string, categoryKey: ?string, categoryValue: ?string, tags: array<string,string>}|null
     */
    private function resolveNightlifePoi(array $run): ?array
    {
        $poi = $this->locationHelper->majorityPoiContext($run);
        if ($poi === null) {
            return null;
        }

        $nightKeywords = ['night', 'club', 'bar', 'pub', 'biergarten', 'lounge', 'casino'];

        $label = $poi['label'] ?? null;
        if (is_string($label)) {
            $normalizedLabel = strtolower($label);
            foreach ($nightKeywords as $keyword) {
                if (str_contains($normalizedLabel, $keyword)) {
                    return $poi;
                }
            }
        }

        $categoryKey   = $poi['categoryKey'] ?? null;
        $categoryValue = $poi['categoryValue'] ?? null;
        if (is_string($categoryKey) || is_string($categoryValue)) {
            $values = [
                strtolower((string) $categoryKey),
                strtolower((string) $categoryValue),
            ];

            foreach ($values as $value) {
                if ($value === '') {
                    continue;
                }

                foreach ($nightKeywords as $keyword) {
                    if (str_contains($value, $keyword)) {
                        return $poi;
                    }
                }
            }
        }

        $tags = $poi['tags'] ?? [];
        if (is_array($tags)) {
            foreach ($tags as $tagValue) {
                if (!is_string($tagValue)) {
                    continue;
                }

                $normalizedValue = strtolower($tagValue);
                foreach ($nightKeywords as $keyword) {
                    if (str_contains($normalizedValue, $keyword)) {
                        return $poi;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{label: string, score: float}> $existing
     * @param list<array{label: string, score: float}> $additional
     *
     * @return list<array{label: string, score: float}>
     */
    private function mergeSceneTagEntries(array $existing, array $additional): array
    {
        $normalized = [];

        foreach ($existing as $entry) {
            $label = $entry['label'];
            $score = $entry['score'];

            if (!isset($normalized[$label]) || $normalized[$label]['score'] < $score) {
                $normalized[$label] = [
                    'label' => $label,
                    'score' => $score,
                ];
            }
        }

        foreach ($additional as $entry) {
            $label = $entry['label'];
            $score = $entry['score'];

            if (!isset($normalized[$label]) || $normalized[$label]['score'] < $score) {
                $normalized[$label] = [
                    'label' => $label,
                    'score' => $score,
                ];
            }
        }

        return array_values($normalized);
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<array{label: string, score: float}>
     */
    private function sanitizeSceneTagList(array $raw): array
    {
        $result = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = $entry['label'] ?? null;
            if (!is_string($label)) {
                continue;
            }

            $score = $entry['score'] ?? null;
            $value = 0.0;
            if (is_float($score) || is_int($score)) {
                $value = (float) $score;
            }

            $result[] = [
                'label' => $label,
                'score' => $value,
            ];
        }

        return $result;
    }
    /**
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void $update
     *
     * @return list<ClusterDraft>
     */
    public function clusterWithProgress(array $items, callable $update): array
    {
        return $this->runWithDefaultProgress($items, $update, fn (array $payload): array => $this->cluster($payload));
    }

}
