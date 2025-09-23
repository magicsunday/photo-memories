<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\AbstractAtHomeClusterStrategy;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Clusters home-based weekday sessions (Mon–Fri) when most photos are within a home radius.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 43])]
final class AtHomeWeekdayClusterStrategy extends AbstractAtHomeClusterStrategy
{
    public function __construct(
        ?float $homeLat = null,
        ?float $homeLon = null,
        float $homeRadiusMeters = 300.0,
        float $minHomeShare = 0.7,
        int $minItemsPerDay = 4,
        int $minItemsTotal = 8,
        string $timezone = 'Europe/Berlin'
    ) {
        parent::__construct($homeLat, $homeLon, $homeRadiusMeters, $minHomeShare, $minItemsPerDay, $minItemsTotal, $timezone);
    }

    public function name(): string
    {
        return 'at_home_weekday';
    }

    protected function isDesiredDay(int $dayOfWeek): bool
    {
        return $dayOfWeek >= 1 && $dayOfWeek <= 5;
    }
}
