<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use MagicSunday\Memories\Utility\Contract\PoiScoringStrategyInterface;

use function floor;
use function is_string;

/**
 * Applies the default POI weighting heuristics.
 */
final class DefaultPoiScorer implements PoiScoringStrategyInterface
{
    private const int POI_NAME_BONUS = 100;

    private const int POI_CATEGORY_VALUE_BONUS = 30;

    private const int POI_WIKIDATA_BONUS = 120;

    private const int POI_DISTANCE_PENALTY_DIVISOR = 25;

    /**
     * Tag specific weightings favouring more significant POIs.
     */
    private const array POI_TAG_WEIGHTS = [
        'tourism'  => 600,
        'historic' => 450,
        'man_made' => 220,
        'leisure'  => 140,
        'natural'  => 140,
        'place'    => 130,
        'sport'    => 60,
        'landuse'  => 25,
    ];

    /**
     * Category key bonuses stacked on top of the tag weights.
     */
    private const array POI_CATEGORY_KEY_BONUS = [
        'tourism'  => 220,
        'historic' => 180,
        'man_made' => 150,
        'leisure'  => 90,
        'natural'  => 90,
        'place'    => 80,
        'sport'    => 50,
    ];

    /**
     * Additional bonuses for specific tag/value combinations.
     */
    private const array POI_TAG_VALUE_BONUS = [
        'man_made:tower' => 260,
    ];

    public function score(array $poi, ?float $distance): int
    {
        $score = 0;

        if ($poi['name'] !== null) {
            $score += self::POI_NAME_BONUS;
        }

        if ($poi['categoryValue'] !== null) {
            $score += self::POI_CATEGORY_VALUE_BONUS;
        }

        $categoryKey = $poi['categoryKey'];
        if ($categoryKey !== null) {
            $score += self::POI_CATEGORY_KEY_BONUS[$categoryKey] ?? 0;
        }

        foreach ($poi['tags'] as $tagKey => $tagValue) {
            if (!is_string($tagValue)) {
                continue;
            }

            $score += self::POI_TAG_WEIGHTS[$tagKey] ?? 0;
            $score += self::POI_TAG_VALUE_BONUS[$tagKey . ':' . $tagValue] ?? 0;
        }

        if (isset($poi['tags']['wikidata'])) {
            $score += self::POI_WIKIDATA_BONUS;
        }

        if ($distance !== null && $distance > 0.0) {
            $score -= (int) floor($distance / self::POI_DISTANCE_PENALTY_DIVISOR);
        }

        return $score;
    }
}
