<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\OverpassElementFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OverpassElementFilterTest extends TestCase
{
    #[Test]
    public function returnsNormalizedElementWhenLatLonPresent(): void
    {
        $filter  = new OverpassElementFilter();
        $element = [
            'type' => 'node',
            'id'   => 123,
            'lat'  => 52.5,
            'lon'  => 13.4,
            'tags' => [
                'tourism' => 'museum',
            ],
        ];

        $normalized = $filter->filter($element);

        self::assertNotNull($normalized);
        self::assertSame('node/123', $normalized['id']);
        self::assertSame(52.5, $normalized['lat']);
        self::assertSame(13.4, $normalized['lon']);
        self::assertSame(['tourism' => 'museum'], $normalized['tags']);
    }

    #[Test]
    public function returnsNullWhenCoordinatesMissing(): void
    {
        $filter  = new OverpassElementFilter();
        $element = [
            'type' => 'node',
            'id'   => 123,
            'tags' => [],
        ];

        self::assertNull($filter->filter($element));
    }

    #[Test]
    public function acceptsCenterCoordinateFallback(): void
    {
        $filter  = new OverpassElementFilter();
        $element = [
            'type'   => 'way',
            'id'     => 456,
            'center' => ['lat' => 48.1, 'lon' => 11.6],
            'tags'   => [],
        ];

        $normalized = $filter->filter($element);

        self::assertNotNull($normalized);
        self::assertSame('way/456', $normalized['id']);
        self::assertSame(48.1, $normalized['lat']);
        self::assertSame(11.6, $normalized['lon']);
    }
}
