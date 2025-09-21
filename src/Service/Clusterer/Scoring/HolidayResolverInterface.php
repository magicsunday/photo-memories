<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Scoring;

use DateTimeImmutable;

/**
 * Minimal interface to check whether a given day is a holiday.
 */
interface HolidayResolverInterface
{
    public function isHoliday(DateTimeImmutable $day): bool;
}
