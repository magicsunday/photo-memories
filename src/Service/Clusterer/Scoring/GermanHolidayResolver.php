<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use DateInterval;
use DateTimeImmutable;
use MagicSunday\Memories\Utility\Calendar;

/**
 * Basic German (federal) holidays only. No state-specific holidays.
 * Adds: New Year, Good Friday, Easter Monday, Labor Day, Ascension, Whit Monday,
 * German Unity Day, Christmas (25.+26.).
 */
final class GermanHolidayResolver implements HolidayResolverInterface
{
    public function isHoliday(\DateTimeImmutable $day): bool
    {
        return Calendar::germanFederalHolidayName($day) !== null;
    }
}
