<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_map;
use function array_slice;
use function array_values;
use function is_string;
use function mb_strtolower;
use function strcasecmp;
use function trim;
use function uasort;

use const PHP_INT_MAX;

/**
 * Small helper to build ClusterDraft fields from Media lists.
 */
trait ClusterBuildHelperTrait
{

    /**
     * @param list<Media> $members
     *
     * @return array{lat: float, lon: float}
     */
    private function computeCentroid(array $members): array
    {
        return MediaMath::centroid($members);
    }

    /**
     * @param list<Media> $members
     *
     * @return list<int>
     */
    private function toMemberIds(array $members): array
    {
        return array_map(static fn (Media $member): int => $member->getId(), $members);
    }

    /**
     * @param list<Media> $members
     *
     * @return array{from:int,to:int}
     */
    private function computeTimeRange(array $members): array
    {
        $from = PHP_INT_MAX;
        $to   = 0;

        foreach ($members as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts !== null) {
                if ($ts < $from) {
                    $from = $ts;
                }

                if ($ts > $to) {
                    $to = $ts;
                }
            }
        }

        if ($from === PHP_INT_MAX) {
            $from = 0;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Collects the most relevant scene tags and keywords from a media list.
     *
     * @param list<Media> $members
     * @param int         $sceneTagLimit
     * @param int         $keywordLimit
     *
     * @return array{
     *     scene_tags?: list<array{label: string, score: float}>,
     *     keywords?: list<string>
     * }
     */
    private function collectDominantTags(
        array $members,
        int $sceneTagLimit = 5,
        int $keywordLimit = 10,
    ): array {
        /** @var array<string, array{score: float, count: int}> $sceneScores */
        $sceneScores = [];

        foreach ($members as $media) {
            $tags = $media->getSceneTags();
            if ($tags === null) {
                continue;
            }

            foreach ($tags as $tag) {
                $label = $tag['label'];
                $value = (float) $tag['score'];

                if ($value < 0.0) {
                    $value = 0.0;
                } elseif ($value > 1.0) {
                    $value = 1.0;
                }

                $entry = $sceneScores[$label] ?? ['score' => 0.0, 'count' => 0];
                if ($value > $entry['score']) {
                    $entry['score'] = $value;
                }

                ++$entry['count'];
                $sceneScores[$label] = $entry;
            }
        }

        /** @var list<array{label: string, score: float}> $sceneTags */
        $sceneTags = [];
        if ($sceneScores !== []) {
            uasort(
                $sceneScores,
                static function (array $a, array $b): int {
                    $scoreCmp = $b['score'] <=> $a['score'];
                    if ($scoreCmp !== 0) {
                        return $scoreCmp;
                    }

                    return $b['count'] <=> $a['count'];
                }
            );

            $sceneScores = array_slice($sceneScores, 0, $sceneTagLimit, true);

            foreach ($sceneScores as $label => $data) {
                $sceneTags[] = [
                    'label' => $label,
                    'score' => $data['score'],
                ];
            }
        }

        /** @var array<string, array{label: string, count: int}> $keywordStats */
        $keywordStats = [];
        foreach ($members as $media) {
            $keywords = $media->getKeywords();
            if ($keywords === null) {
                continue;
            }

            foreach ($keywords as $keyword) {
                if (!is_string($keyword)) {
                    continue;
                }

                $trimmed = trim($keyword);
                if ($trimmed === '') {
                    continue;
                }

                $normalized = mb_strtolower($trimmed);
                $entry      = $keywordStats[$normalized] ?? ['label' => $trimmed, 'count' => 0];
                if ($entry['label'] === '') {
                    $entry['label'] = $trimmed;
                }

                ++$entry['count'];
                $keywordStats[$normalized] = $entry;
            }
        }

        /** @var list<string> $keywords */
        $keywords = [];
        if ($keywordStats !== []) {
            uasort(
                $keywordStats,
                static function (array $a, array $b): int {
                    $countCmp = $b['count'] <=> $a['count'];
                    if ($countCmp !== 0) {
                        return $countCmp;
                    }

                    return strcasecmp($a['label'], $b['label']);
                }
            );

            $keywordStats = array_slice($keywordStats, 0, $keywordLimit, true);
            $keywords     = array_values(array_map(
                static fn (array $entry): string => $entry['label'],
                $keywordStats
            ));
        }

        $result = [];
        if ($sceneTags !== []) {
            $result['scene_tags'] = $sceneTags;
        }

        if ($keywords !== []) {
            $result['keywords'] = $keywords;
        }

        return $result;
    }

    /**
     * @param list<Media> $members
     *
     * @return array{
     *     people: float,
     *     people_count: int,
     *     people_unique: int,
     *     people_coverage: float,
     *     people_face_coverage: float
     * }
     */
    private function buildPeopleParams(array $members): array
    {
        /** @var ClusterPeopleAggregator|null $peopleAggregator */
        static $peopleAggregator = null;

        if ($peopleAggregator === null) {
            $peopleAggregator = new ClusterPeopleAggregator();
        }

        return $peopleAggregator->buildParams($members);
    }
}
