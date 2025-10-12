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

        $sequence = TransitionSequenceGenerator::generate(
            $transitions,
            [10, 20, 30],
            [
                '/slides/10.jpg',
                '/slides/20.jpg',
                '/slides/30.jpg',
            ],
            24,
            'Reise nach Berlin',
            'Frühjahr 2024',
        );

        self::assertCount(24, $sequence);

        $sequenceCount = count($sequence);
        for ($index = 1; $index < $sequenceCount; $index++) {
            self::assertNotSame($sequence[$index - 1], $sequence[$index]);
        }
    }

    public function testGenerateSupportsSingleTransition(): void
    {
        $transitions = ['fade'];

        $sequence = TransitionSequenceGenerator::generate(
            $transitions,
            [1, 2, 3],
            [
                '/slides/1.jpg',
                '/slides/2.jpg',
                '/slides/3.jpg',
            ],
            5,
            'Familie',
            null,
        );

        self::assertSame([
            'fade',
            'fade',
            'fade',
            'fade',
            'fade',
        ], $sequence);
    }

    public function testGenerateProducesDeterministicSequenceForIdenticalMetadata(): void
    {
        $transitions = ['fade', 'wipe', 'zoom'];

        $metadata = [
            [10, 11, 12],
            [
                '/slides/a.jpg',
                '/slides/b.jpg',
                '/slides/c.jpg',
            ],
            'Sommerferien',
            '2023',
        ];

        $first = TransitionSequenceGenerator::generate(
            $transitions,
            $metadata[0],
            $metadata[1],
            3,
            $metadata[2],
            $metadata[3],
        );

        $second = TransitionSequenceGenerator::generate(
            $transitions,
            $metadata[0],
            $metadata[1],
            3,
            $metadata[2],
            $metadata[3],
        );

        self::assertSame($first, $second);
    }

    public function testGenerateChangesOrderWhenMetadataDiffers(): void
    {
        $transitions = ['fade', 'wipe', 'zoom', 'slide', 'cube'];

        $mediaIds = [21, 22, 23, 24, 25];
        $imagePaths = [
            '/slides/base-1.jpg',
            '/slides/base-2.jpg',
            '/slides/base-3.jpg',
            '/slides/base-4.jpg',
            '/slides/base-5.jpg',
        ];
        $slideCount = count($mediaIds);

        $baseSequence = TransitionSequenceGenerator::generate(
            $transitions,
            $mediaIds,
            $imagePaths,
            $slideCount,
            'Herbsturlaub',
            'Berge',
        );

        $changedPathSequence = TransitionSequenceGenerator::generate(
            $transitions,
            $mediaIds,
            [
                '/slides/base-1.jpg',
                '/slides/base-2.jpg',
                '/slides/var-3.jpg',
                '/slides/base-4.jpg',
                '/slides/base-5.jpg',
            ],
            $slideCount,
            'Herbsturlaub',
            'Berge',
        );

        self::assertNotSame($baseSequence, $changedPathSequence);

        $changedTitleSequence = TransitionSequenceGenerator::generate(
            $transitions,
            $mediaIds,
            $imagePaths,
            $slideCount,
            'Winterurlaub',
            'Berge',
        );

        self::assertNotSame($baseSequence, $changedTitleSequence);

        $changedSubtitleSequence = TransitionSequenceGenerator::generate(
            $transitions,
            $mediaIds,
            $imagePaths,
            $slideCount,
            'Herbsturlaub',
            'Meer 2024',
        );

        self::assertNotSame($baseSequence, $changedSubtitleSequence);
    }
}
