<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Support;

use MagicSunday\Memories\Clusterer\Support\HomeBoundaryHelper;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class HomeBoundaryHelperTest extends TestCase
{
    #[Test]
    public function filtersCentersByTimestamp(): void
    {
        $home = [
            'lat'       => 52.5,
            'lon'       => 13.4,
            'radius_km' => 10.0,
            'centers'   => [
                [
                    'lat'         => 52.5,
                    'lon'         => 13.4,
                    'radius_km'   => 10.0,
                    'valid_from'  => 100,
                    'valid_until' => 200,
                ],
                [
                    'lat'         => 48.1,
                    'lon'         => 11.5,
                    'radius_km'   => 12.0,
                    'valid_from'  => 250,
                    'valid_until' => 400,
                ],
            ],
        ];

        $active = HomeBoundaryHelper::centers($home, 150);

        self::assertCount(1, $active);
        self::assertSame(100, $active[0]['valid_from']);
        self::assertSame(200, $active[0]['valid_until']);

        $fallback = HomeBoundaryHelper::centers($home, 50);

        self::assertCount(2, $fallback);
    }

    #[Test]
    public function nearestCenterRespectsValidityWindows(): void
    {
        $home = [
            'lat'       => 52.5,
            'lon'       => 13.4,
            'radius_km' => 10.0,
            'centers'   => [
                [
                    'lat'         => 52.5,
                    'lon'         => 13.4,
                    'radius_km'   => 10.0,
                    'valid_from'  => 100,
                    'valid_until' => 200,
                ],
                [
                    'lat'         => 48.1,
                    'lon'         => 11.5,
                    'radius_km'   => 12.0,
                    'valid_from'  => 250,
                    'valid_until' => 400,
                ],
            ],
        ];

        $first = HomeBoundaryHelper::nearestCenter($home, 52.51, 13.41, 150);
        self::assertSame(0, $first['index']);

        $second = HomeBoundaryHelper::nearestCenter($home, 48.11, 11.51, 300);
        self::assertSame(1, $second['index']);

        $fallback = HomeBoundaryHelper::nearestCenter($home, 48.11, 11.51, 50);
        self::assertSame(1, $fallback['index']);
    }
}
