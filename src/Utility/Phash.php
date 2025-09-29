<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Utility;

/**
 * pHash helpers with Hamming distance for hex strings.
 */
final class Phash
{
    public static function hammingFromHex(string $a, string $b): int
    {
        $ab = \hex2bin($a);
        $bb = \hex2bin($b);

        if ($ab === false || $bb === false) {
            return 64;
        }

        $len  = \min(\strlen($ab), \strlen($bb));
        $dist = 0;

        for ($i = 0; $i < $len; $i++) {
            $dist += self::bitcount(\ord($ab[$i]) ^ \ord($bb[$i]));
        }

        return $dist + 8 * \abs(\strlen($ab) - \strlen($bb));
    }

    private static function bitcount(int $v): int
    {
        $c = 0;
        while ($v !== 0) {
            $v &= $v - 1;
            $c++;
        }

        return $c;
    }
}
