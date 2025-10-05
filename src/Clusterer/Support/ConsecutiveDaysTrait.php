<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Helper trait providing utilities for working with consecutive day strings.
 */
trait ConsecutiveDaysTrait
{
    private function isNextDay(string $a, string $b): bool
    {
        $timezone = new DateTimeZone('UTC');

        $first  = DateTimeImmutable::createFromFormat('!Y-m-d', $a, $timezone);
        $second = DateTimeImmutable::createFromFormat('!Y-m-d', $b, $timezone);

        if ($first === false || $second === false) {
            return false;
        }

        return $first->modify('+1 day')->format('Y-m-d') === $second->format('Y-m-d');
    }
}
