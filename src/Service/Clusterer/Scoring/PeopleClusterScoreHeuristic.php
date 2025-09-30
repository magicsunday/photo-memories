<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;

use function count;
use function is_array;
use function is_string;

final class PeopleClusterScoreHeuristic extends AbstractClusterScoreHeuristic
{
    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $mediaItems = $this->collectMediaItems($cluster, $mediaMap);
        $params     = $cluster->getParams();

        $members       = count($cluster->getMembers());
        $peopleMetrics = $this->computePeopleMetrics($mediaItems, $members, $params);

        $cluster->setParam('people', $peopleMetrics['score']);
        $cluster->setParam('people_count', $peopleMetrics['mentions']);
        $cluster->setParam('people_unique', $peopleMetrics['unique']);
        $cluster->setParam('people_coverage', $peopleMetrics['coverage']);
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['people'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'people';
    }

    /**
     * @param list<Media>         $mediaItems
     * @param int                 $members
     * @param array<string,mixed> $params
     *
     * @return array{score:float,unique:int,mentions:int,coverage:float}
     */
    private function computePeopleMetrics(array $mediaItems, int $members, array $params): array
    {
        if (isset($params['people']) && $this->floatOrNull($params['people']) !== null) {
            $score    = $this->clamp01((float) $params['people']);
            $mentions = (int) ($params['people_count'] ?? 0);
            $unique   = (int) ($params['people_unique'] ?? 0);
            $coverage = $this->clamp01($this->floatOrNull($params['people_coverage'] ?? null));

            return [
                'score'    => $score,
                'unique'   => $unique,
                'mentions' => $mentions,
                'coverage' => $coverage,
            ];
        }

        $uniqueNames     = [];
        $mentions        = 0;
        $itemsWithPeople = 0;

        foreach ($mediaItems as $media) {
            $persons = $media->getPersons();
            if (!is_array($persons) || $persons === []) {
                continue;
            }

            ++$itemsWithPeople;
            foreach ($persons as $person) {
                if (!is_string($person) || $person === '') {
                    continue;
                }

                $uniqueNames[$person] = true;
                ++$mentions;
            }
        }

        $unique       = count($uniqueNames);
        $coverage     = $members > 0 ? $itemsWithPeople / $members : 0.0;
        $richness     = $unique > 0 ? min(1.0, $unique / 4.0) : 0.0;
        $mentionScore = $members > 0 ? min(1.0, $mentions / (float) max(1, $members)) : 0.0;

        $score = $this->combineScores([
            [$coverage, 0.4],
            [$richness, 0.35],
            [$mentionScore, 0.25],
        ], 0.0);

        return [
            'score'    => $score,
            'unique'   => $unique,
            'mentions' => $mentions,
            'coverage' => $coverage,
        ];
    }
}
