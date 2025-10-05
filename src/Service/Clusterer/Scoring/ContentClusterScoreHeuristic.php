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
use function mb_strtolower;
use function min;

/**
 * Class ContentClusterScoreHeuristic
 */
final class ContentClusterScoreHeuristic extends AbstractClusterScoreHeuristic
{
    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $mediaItems = $this->collectMediaItems($cluster, $mediaMap);
        $params     = $cluster->getParams();
        $members    = count($cluster->getMembers());

        $metrics = $this->computeContentMetrics($mediaItems, $members, $params);

        $cluster->setParam('content', $metrics['score']);
        $cluster->setParam('content_keywords_unique', $metrics['unique_keywords']);
        $cluster->setParam('content_keywords_total', $metrics['total_keywords']);
        $cluster->setParam('content_coverage', $metrics['coverage']);
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['content'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'content';
    }

    /**
     * @param list<Media>         $mediaItems
     * @param int                 $members
     * @param array<string,mixed> $params
     *
     * @return array{score:float,unique_keywords:int,total_keywords:int,coverage:float}
     */
    private function computeContentMetrics(array $mediaItems, int $members, array $params): array
    {
        $cachedContentScore = $this->floatOrNull($params['content'] ?? null);

        if (($score = $cachedContentScore) !== null) {
            $unique   = (int) ($params['content_keywords_unique'] ?? 0);
            $total    = (int) ($params['content_keywords_total'] ?? 0);
            $coverage = $this->clamp01($this->floatOrNull($params['content_coverage'] ?? null));

            return [
                'score'           => $this->clamp01($score),
                'unique_keywords' => $unique,
                'total_keywords'  => $total,
                'coverage'        => $coverage,
            ];
        }

        $uniqueKeywords    = [];
        $totalKeywords     = 0;
        $itemsWithKeywords = 0;

        foreach ($mediaItems as $media) {
            $keywords = $media->getKeywords();
            if (!is_array($keywords) || $keywords === []) {
                continue;
            }

            ++$itemsWithKeywords;
            foreach ($keywords as $keyword) {
                if (!is_string($keyword) || $keyword === '') {
                    continue;
                }

                $uniqueKeywords[mb_strtolower($keyword)] = true;
                ++$totalKeywords;
            }
        }

        $unique   = count($uniqueKeywords);
        $coverage = $members > 0 ? $itemsWithKeywords / $members : 0.0;
        $richness = $unique > 0 ? min(1.0, $unique / 8.0) : 0.0;
        $density  = $members > 0 ? min(1.0, $totalKeywords / (float) max(1, $members)) : 0.0;

        $score = $this->combineScores([
            [$coverage, 0.4],
            [$richness, 0.35],
            [$density, 0.25],
        ]);

        return [
            'score'           => $score,
            'unique_keywords' => $unique,
            'total_keywords'  => $totalKeywords,
            'coverage'        => $coverage,
        ];
    }
}
