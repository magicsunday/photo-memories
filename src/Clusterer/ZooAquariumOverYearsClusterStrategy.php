<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\KeywordBestDayOverYearsStrategy;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Picks best zoo/aquarium day per year and aggregates over years.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 62])]
final class ZooAquariumOverYearsClusterStrategy extends KeywordBestDayOverYearsStrategy
{
    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $minItemsPerDay = 5,
        int $minYears = 3,
        int $minItemsTotal = 18
    ) {
        parent::__construct(
            timezone: $timezone,
            minItemsPerDay: $minItemsPerDay,
            minYears: $minYears,
            minItemsTotal: $minItemsTotal,
            keywords: ['zoo', 'tierpark', 'wildpark', 'safari park', 'aquarium', 'sealife', 'sea life', 'zoopark']
        );
    }

    public function name(): string
    {
        return 'zoo_aquarium_over_years';
    }
}
