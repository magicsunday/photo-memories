<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use function strtotime;

/**
 * Helper trait providing utilities for working with consecutive day strings.
 */
trait ConsecutiveDaysTrait
{
    private function isNextDay(string $a, string $b): bool
    {
        $ta = strtotime($a . ' 00:00:00');
        $tb = strtotime($b . ' 00:00:00');

        if ($ta === false || $tb === false) {
            return false;
        }

        return ($tb - $ta) === 86400;
    }
}
