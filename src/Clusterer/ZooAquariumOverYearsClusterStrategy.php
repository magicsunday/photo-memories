<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\KeywordBestDayOverYearsStrategy;

/**
 * Picks best zoo/aquarium day per year and aggregates over years.
 */
final class ZooAquariumOverYearsClusterStrategy extends KeywordBestDayOverYearsStrategy
{
    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $minItemsPerDay = 5,
        int $minYears = 3,
        int $minItemsTotal = 18,
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
