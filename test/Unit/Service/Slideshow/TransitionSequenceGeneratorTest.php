<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Slideshow;

use MagicSunday\Memories\Service\Slideshow\TransitionSequenceGenerator;
use MagicSunday\Memories\Test\TestCase;

/**
 * @covers \MagicSunday\Memories\Service\Slideshow\TransitionSequenceGenerator
 */
final class TransitionSequenceGeneratorTest extends TestCase
{
    public function testGenerateAvoidsConsecutiveDuplicatesWhenMultipleTransitionsExist(): void
    {
        $transitions = [
            'fade',
            'wipe',
            'zoom',
        ];

        $sequence = TransitionSequenceGenerator::generate($transitions, [10, 20, 30], 24);

        self::assertCount(24, $sequence);

        $sequenceCount = count($sequence);
        for ($index = 1; $index < $sequenceCount; $index++) {
            self::assertNotSame($sequence[$index - 1], $sequence[$index]);
        }
    }

    public function testGenerateSupportsSingleTransition(): void
    {
        $transitions = ['fade'];

        $sequence = TransitionSequenceGenerator::generate($transitions, [1, 2, 3], 5);

        self::assertSame([
            'fade',
            'fade',
            'fade',
            'fade',
            'fade',
        ], $sequence);
    }
}
