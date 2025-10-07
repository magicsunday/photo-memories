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
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\LocalTimeHelper;
use MagicSunday\Memories\Utility\LocationHelper;

/**
 * Clusters home-based weekday sessions (Mon–Fri) when most photos are within a home radius.
 */
final class AtHomeWeekdayClusterStrategy extends AbstractAtHomeClusterStrategy
{
    public function __construct(
        LocalTimeHelper $localTimeHelper,
        LocationHelper $locationHelper,
        ?float $homeLat = null,
        ?float $homeLon = null,
        float $homeRadiusMeters = 300.0,
        float $minHomeShare = 0.7,
        int $minItemsPerDay = 4,
        int $minItemsTotal = 8,
        string $homeVersionHash = '',
        ?ClusterQualityAggregator $qualityAggregator = null,
    ) {
        parent::__construct(
            algorithm: 'at_home_weekday',
            allowedWeekdays: [1, 2, 3, 4, 5],
            homeLat: $homeLat,
            homeLon: $homeLon,
            homeRadiusMeters: $homeRadiusMeters,
            minHomeShare: $minHomeShare,
            minItemsPerDay: $minItemsPerDay,
            minItemsTotal: $minItemsTotal,
            localTimeHelper: $localTimeHelper,
            locationHelper: $locationHelper,
            qualityAggregator: $qualityAggregator,
            homeVersionHash: $homeVersionHash,
        );
    }
}
