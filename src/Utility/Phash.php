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
use function ctype_xdigit;
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
        $ab = self::decodeHex($a);
        $bb = self::decodeHex($b);

        if ($ab === null || $bb === null) {
            return 64;
        }

        $len  = min(strlen($ab), strlen($bb));
        $dist = 0;

        for ($i = 0; $i < $len; ++$i) {
            $dist += self::bitcount(ord($ab[$i]) ^ ord($bb[$i]));
        }

        return $dist + 8 * abs(strlen($ab) - strlen($bb));
    }

    /**
     * Decode a hexadecimal string to binary representation while validating its format.
     */
    private static function decodeHex(string $value): ?string
    {
        if ((strlen($value) & 1) === 1) {
            return null;
        }

        if ($value !== '' && ctype_xdigit($value) === false) {
            return null;
        }

        $decoded = hex2bin($value);

        if ($decoded === false) {
            return null;
        }

        return $decoded;
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
