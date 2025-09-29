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
 * Picks the best "beach day" per year (based on filename keywords) and aggregates over years.
 */
final class BeachOverYearsClusterStrategy extends KeywordBestDayOverYearsStrategy
{
    public function __construct(
        string $timezone = 'Europe/Berlin',
        int $minItemsPerDay = 6,
        int $minYears = 3,
        int $minItemsTotal = 24,
    ) {
        parent::__construct(
            timezone: $timezone,
            minItemsPerDay: $minItemsPerDay,
            minYears: $minYears,
            minItemsTotal: $minItemsTotal,
            keywords: ['strand', 'beach', 'meer', 'ocean', 'küste', 'kueste', 'coast', 'seaside', 'baltic', 'ostsee', 'nordsee', 'adriatic']
        );
    }

    public function name(): string
    {
        return 'beach_over_years';
    }
}
