<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;

use function array_key_exists;
use function array_values;
use function assert;
use function count;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function str_contains;
use function strtolower;
use function usort;

/**
 * Heuristic "Golden Hour" clusters around morning/evening hours.
 */
final readonly class GoldenHourClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;

    private ClusterQualityAggregator $qualityAggregator;

    public function __construct(
        private LocalTimeHelper $localTimeHelper,
        private LocationHelper $locationHelper,
        /** Inclusive local hours considered golden-hour candidates. */
        private array $morningHours = [6, 7, 8],
        private array $eveningHours = [18, 19, 20],
        private int $sessionGapSeconds = 90 * 60,
        private int $minItemsPerRun = 5,
        ?ClusterQualityAggregator $qualityAggregator = null,
    ) {
        if ($this->morningHours === [] || $this->eveningHours === []) {
            throw new InvalidArgumentException('Morning and evening hours must not be empty.');
        }

        foreach ([$this->morningHours, $this->eveningHours] as $hours) {
            foreach ($hours as $hour) {
                if (!is_int($hour) || $hour < 0 || $hour > 23) {
                    throw new InvalidArgumentException('Hour values must be integers within 0..23.');
                }
            }
        }

        if ($this->sessionGapSeconds < 1) {
            throw new InvalidArgumentException('sessionGapSeconds must be >= 1.');
        }

        if ($this->minItemsPerRun < 1) {
            throw new InvalidArgumentException('minItemsPerRun must be >= 1.');
        }

        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
    }

    public function name(): string
    {
        return 'golden_hour';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $cand */
        $cand = $this->filterTimestampedItemsBy(
            $items,
            function (Media $m): bool {
                $bag = $m->getFeatureBag();
                $isGoldenHour = $bag->solarIsGoldenHour();
                if ($isGoldenHour !== null) {
                    return $isGoldenHour === true;
                }

                $local = $this->localTimeHelper->resolve($m);
                assert($local instanceof DateTimeImmutable);
                $h = (int) $local->format('G');

                return in_array($h, $this->morningHours, true)
                    || in_array($h, $this->eveningHours, true);
            }
        );

        if (count($cand) < $this->minItemsPerRun) {
            return [];
        }

        usort($cand, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        /** @var list<list<Media>> $runs */
        $runs = [];
        /** @var list<Media> $buf */
        $buf    = [];
        $lastTs = null;

        foreach ($cand as $m) {
            $ts = $m->getTakenAt()->getTimestamp();
            if ($lastTs !== null && ($ts - $lastTs) > $this->sessionGapSeconds && $buf !== []) {
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
            $centroid  = $this->computeCentroid($run);
            $time      = $this->computeTimeRange($run);
            $sceneTags = $this->collectSceneTags($run);

            $params = [
                'time_range' => $time,
            ];

            if ($sceneTags !== []) {
                $params['scene_tags'] = $sceneTags;
            }

            $tagMetadata = $this->collectDominantTags($run);
            $keywords    = $tagMetadata['keywords'] ?? null;
            if (is_array($keywords) && $keywords !== []) {
                $params['keywords'] = $keywords;
            }

            $qualityParams = $this->qualityAggregator->buildParams($run);
            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $peopleParams = $this->buildPeopleParams($run);
            $params       = [...$params, ...$peopleParams];

            $params = $this->appendLocationMetadata($run, $params);

            $out[] = new ClusterDraft(
                algorithm: 'golden_hour',
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: $this->toMemberIds($run)
            );
        }

        return $out;
    }

    /**
     * @param list<Media> $run
     *
     * @return list<array{label: string, score: float}>
     */
    private function collectSceneTags(array $run): array
    {
        $keywords  = ['sunset', 'sunrise', 'dawn', 'dusk', 'golden'];
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
}
