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
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_key_exists;
use function array_map;
use function array_values;
use function assert;
use function count;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function strtolower;
use function str_contains;
use function usort;


/**
 * Heuristic "Golden Hour" clusters around morning/evening hours.
 */
final readonly class GoldenHourClusterStrategy implements ClusterStrategyInterface
{
    use MediaFilterTrait;

    public function __construct(
        private LocalTimeHelper $localTimeHelper,
        /** Inclusive local hours considered golden-hour candidates. */
        private array $morningHours = [6, 7, 8],
        private array $eveningHours = [18, 19, 20],
        private int $sessionGapSeconds = 90 * 60,
        private int $minItemsPerRun = 5,
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
                $features = $m->getFeatures();
                if (is_array($features) && array_key_exists('isGoldenHour', $features)) {
                    return $features['isGoldenHour'] === true;
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
            $ts = (int) $m->getTakenAt()->getTimestamp();
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
            $centroid  = MediaMath::centroid($run);
            $time      = MediaMath::timeRange($run);
            $sceneTags = $this->collectSceneTags($run);

            $params = [
                'time_range' => $time,
            ];

            if ($sceneTags !== []) {
                $params['scene_tags'] = $sceneTags;
            }

            $out[] = new ClusterDraft(
                algorithm: 'golden_hour',
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $run)
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
        $keywords = ['sunset', 'sunrise', 'dawn', 'dusk', 'golden'];
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
