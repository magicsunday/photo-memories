<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

use function abs;
use function hex2bin;
use function min;
use function ord;
use function strlen;

/**
 * pHash helpers with Hamming distance for hex strings.
 */
final class Phash
{
    public static function hammingFromHex(string $a, string $b): int
    {
        $ab = hex2bin($a);
        $bb = hex2bin($b);

        if ($ab === false || $bb === false) {
            return 64;
        }

        $len  = min(strlen($ab), strlen($bb));
        $dist = 0;

        for ($i = 0; $i < $len; ++$i) {
            $dist += self::bitcount(ord($ab[$i]) ^ ord($bb[$i]));
        }

        return $dist + 8 * abs(strlen($ab) - strlen($bb));
    }

    private static function bitcount(int $v): int
    {
        $c = 0;
        while ($v !== 0) {
            $v &= $v - 1;
            ++$c;
        }

        return $c;
    }
}
