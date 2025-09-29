<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

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
