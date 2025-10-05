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

use function is_array;
use function is_numeric;

/**
 * Class PoiClusterScoreHeuristic.
 */
final class PoiClusterScoreHeuristic extends AbstractClusterScoreHeuristic
{
    /** @param array<string,float> $poiCategoryBoosts */
    public function __construct(private readonly array $poiCategoryBoosts = [])
    {
    }

    public function supports(ClusterDraft $cluster): bool
    {
        return true;
    }

    public function enrich(ClusterDraft $cluster, array $mediaMap): void
    {
        $cluster->setParam('poi_score', $this->computePoiScore($cluster));
    }

    public function score(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();

        return $this->floatOrNull($params['poi_score'] ?? null) ?? 0.0;
    }

    public function weightKey(): string
    {
        return 'poi';
    }

    private function computePoiScore(ClusterDraft $cluster): float
    {
        $params = $cluster->getParams();
        if (isset($params['poi_score']) && is_numeric($params['poi_score'])) {
            return $this->clamp01((float) $params['poi_score']);
        }

        $label         = $this->stringOrNull($params['poi_label'] ?? null);
        $categoryKey   = $this->stringOrNull($params['poi_category_key'] ?? null);
        $categoryValue = $this->stringOrNull($params['poi_category_value'] ?? null);
        $tags          = is_array($params['poi_tags'] ?? null) ? $params['poi_tags'] : [];

        $score = 0.0;
        if ($label !== null) {
            $score += 0.45;
        }

        if ($categoryKey !== null || $categoryValue !== null) {
            $score += 0.25;
        }

        $score += $this->lookupPoiCategoryBoost($categoryKey, $categoryValue);

        if (is_array($tags)) {
            if ($this->stringOrNull($tags['wikidata'] ?? null) !== null) {
                $score += 0.15;
            }

            if ($this->stringOrNull($tags['website'] ?? null) !== null) {
                $score += 0.05;
            }
        }

        return $this->clamp01($score);
    }

    private function lookupPoiCategoryBoost(?string $categoryKey, ?string $categoryValue): float
    {
        if ($this->poiCategoryBoosts === []) {
            return 0.0;
        }

        $boost = 0.0;

        if ($categoryKey !== null) {
            $boost += (float) ($this->poiCategoryBoosts[$categoryKey . '/*'] ?? 0.0);
        }

        if ($categoryValue !== null) {
            $boost += (float) ($this->poiCategoryBoosts['*/' . $categoryValue] ?? 0.0);
        }

        if ($categoryKey !== null && $categoryValue !== null) {
            $boost += (float) ($this->poiCategoryBoosts[$categoryKey . '/' . $categoryValue] ?? 0.0);
        }

        return $boost;
    }
}
