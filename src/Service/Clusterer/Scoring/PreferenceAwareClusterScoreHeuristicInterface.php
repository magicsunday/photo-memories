<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use MagicSunday\Memories\Service\Feed\FeedUserPreferences;

/**
 * Marker interface for heuristics that adjust their behaviour based on feed user preferences.
 */
interface PreferenceAwareClusterScoreHeuristicInterface
{
    public function setFeedUserPreferences(?FeedUserPreferences $preferences): void;
}
