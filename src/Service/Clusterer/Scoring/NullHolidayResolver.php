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
 * Fallback resolver that treats every day as a non-holiday.
 */
final class NullHolidayResolver implements HolidayResolverInterface
{
    public function isHoliday(DateTimeImmutable $day): bool
    {
        return false;
    }
}
