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
use MagicSunday\Memories\Clusterer\Support\ClusterPeopleAggregator;
use MagicSunday\Memories\Entity\Media;

use function count;

/**
 * Class PeopleClusterScoreHeuristic.
 */
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
        $cluster->setParam('people_face_coverage', $peopleMetrics['faceCoverage']);
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
     * @return array{score:float,unique:int,mentions:int,coverage:float,faceCoverage:float}
     */
    private function computePeopleMetrics(array $mediaItems, int $members, array $params): array
    {
        $cachedPeopleScore = $this->floatOrNull($params['people'] ?? null);

        if (($score = $cachedPeopleScore) !== null) {
            $score    = $this->clamp01($score);
            $mentions = (int) ($params['people_count'] ?? 0);
            $unique   = (int) ($params['people_unique'] ?? 0);
            $coverage = $this->clamp01($this->floatOrNull($params['people_coverage'] ?? null));
            $face     = $this->clamp01($this->floatOrNull($params['people_face_coverage'] ?? null));

            return [
                'score'    => $score,
                'unique'   => $unique,
                'mentions' => $mentions,
                'coverage' => $coverage,
                'faceCoverage' => $face,
            ];
        }

        $aggregator   = new ClusterPeopleAggregator();
        $peopleParams = $aggregator->buildParams($mediaItems);

        return [
            'score'    => $peopleParams['people'],
            'unique'   => $peopleParams['people_unique'],
            'mentions' => $peopleParams['people_count'],
            'coverage' => $peopleParams['people_coverage'],
            'faceCoverage' => $peopleParams['people_face_coverage'],
        ];
    }
}
