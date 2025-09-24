<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractAtHomeClusterStrategy;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters home-based weekend sessions: Saturday/Sunday where most photos are within a home radius.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 44])]
final class AtHomeWeekendClusterStrategy extends AbstractAtHomeClusterStrategy
{
    public function __construct(
        ?float $homeLat = null,
        ?float $homeLon = null,
        float $homeRadiusMeters = 300.0,
        float $minHomeShare = 0.7,
        int $minItemsPerDay = 4,
        int $minItemsTotal = 6,
        string $timezone = 'Europe/Berlin',
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
            timezone: $timezone,
        );
    }
}
