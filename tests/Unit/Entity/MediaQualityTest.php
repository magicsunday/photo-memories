<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Entity;

use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class MediaQualityTest extends TestCase
{
    #[Test]
    #[DataProvider('scoreProvider')]
    public function clampsAggregatedQualityScore(?float $input, ?float $expected): void
    {
        $media = $this->makeMedia(
            id: 101,
            path: '/library/quality-score.jpg',
        );

        $media->setQualityScore($input);

        self::assertSame($expected, $media->getQualityScore());
    }

    #[Test]
    #[DataProvider('scoreProvider')]
    public function clampsAggregatedQualityExposure(?float $input, ?float $expected): void
    {
        $media = $this->makeMedia(
            id: 102,
            path: '/library/quality-exposure.jpg',
        );

        $media->setQualityExposure($input);

        self::assertSame($expected, $media->getQualityExposure());
    }

    #[Test]
    #[DataProvider('scoreProvider')]
    public function clampsAggregatedQualityNoise(?float $input, ?float $expected): void
    {
        $media = $this->makeMedia(
            id: 103,
            path: '/library/quality-noise.jpg',
        );

        $media->setQualityNoise($input);

        self::assertSame($expected, $media->getQualityNoise());
    }

    /**
     * @return iterable<string, array{?float, ?float}>
     */
    public static function scoreProvider(): iterable
    {
        yield 'null value' => [null, null];
        yield 'below lower bound' => [-0.75, 0.0];
        yield 'within range' => [0.65, 0.65];
        yield 'above upper bound' => [1.25, 1.0];
    }
}
