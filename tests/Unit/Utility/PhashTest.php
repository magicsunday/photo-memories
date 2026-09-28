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
use PHPUnit\Framework\Attributes\DataProvider;

final class PhashTest extends TestCase
{
    #[DataProvider('provideInvalidHexPairs')]
    public function testHammingFromHexReturnsMaxDistanceForInvalidHex(string $a, string $b): void
    {
        self::assertSame(64, Phash::hammingFromHex($a, $b));
    }

    #[DataProvider('provideValidHexPairs')]
    public function testHammingFromHexCalculatesDistanceForValidHex(string $a, string $b, int $expectedDistance): void
    {
        self::assertSame($expectedDistance, Phash::hammingFromHex($a, $b));
    }

    public static function provideInvalidHexPairs(): iterable
    {
        yield 'odd length first argument' => ['abc', '00'];
        yield 'odd length second argument' => ['00', 'abc'];
        yield 'non-hex characters in first argument' => ['zz', '00'];
        yield 'non-hex characters in second argument' => ['00', 'gg'];
    }

    public static function provideValidHexPairs(): iterable
    {
        yield 'empty strings' => ['', '', 0];
        yield 'uppercase vs lowercase' => ['ffffffffffffffff', 'FFFFFFFFFFFFFFFF', 0];
        yield 'maximum distance' => ['ffffffffffffffff', '0000000000000000', 64];
        yield 'different byte lengths' => ['00', '0000', 8];
        yield 'length and bit difference' => ['ff', '0000', 16];
        yield from self::provideLongHexPairs();
    }

    public static function provideLongHexPairs(): iterable
    {
        yield 'long maximum distance' => [
            'ffffffffffffffffffffffffffffffff',
            '00000000000000000000000000000000',
            128,
        ];
        yield 'long single bit difference' => [
            'ffffffffffffffffffffffffffffffff',
            'fffffffffffffffffffffffffffffffe',
            1,
        ];
    }

    #[DataProvider('provideLongHexPairs')]
    public function testHammingFromHexIsCommutativeForLongHexStrings(string $a, string $b, int $expectedDistance): void
    {
        self::assertSame($expectedDistance, Phash::hammingFromHex($a, $b));
        self::assertSame($expectedDistance, Phash::hammingFromHex($b, $a));
    }
}
