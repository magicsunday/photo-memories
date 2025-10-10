<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Utility;

use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\Phash;

final class PhashTest extends TestCase
{
    public function hammingFromHexReturnsMaxDistanceForInvalidHex(): void
    {
        self::assertSame(64, Phash::hammingFromHex('abc', '00'));
        self::assertSame(64, Phash::hammingFromHex('00', 'abc'));
    }

    public function hammingFromHexCalculatesDistanceForValidHex(): void
    {
        $distance = Phash::hammingFromHex('ffffffffffffffff', '0000000000000000');

        self::assertSame(64, $distance);
    }
}
