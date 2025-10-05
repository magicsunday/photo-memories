<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractAtHomeClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;

/**
 * Clusters home-based weekend sessions: Saturday/Sunday where most photos are within a home radius.
 */
final class AtHomeWeekendClusterStrategy extends AbstractAtHomeClusterStrategy
{
    public function __construct(
        LocalTimeHelper $localTimeHelper,
        ?float $homeLat = null,
        ?float $homeLon = null,
        float $homeRadiusMeters = 300.0,
        float $minHomeShare = 0.7,
        int $minItemsPerDay = 4,
        int $minItemsTotal = 6,
        string $homeVersionHash = '',
    ) {
        parent::__construct(
            algorithm: 'at_home_weekend',
            allowedWeekdays: [6, 7],
            homeLat: $homeLat,
            homeLon: $homeLon,
            homeRadiusMeters: $homeRadiusMeters,
            minHomeShare: $minHomeShare,
            minItemsPerDay: $minItemsPerDay,
            minItemsTotal: $minItemsTotal,
            localTimeHelper: $localTimeHelper,
            homeVersionHash: $homeVersionHash,
        );
    }
}
