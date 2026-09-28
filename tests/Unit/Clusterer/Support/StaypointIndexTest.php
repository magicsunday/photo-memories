<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\StaypointIndex;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class StaypointIndexTest extends TestCase
{
    #[Test]
    public function mapsMediaToStaypointKeys(): void
    {
        $timezone = new DateTimeZone('UTC');
        $base     = new DateTimeImmutable('2024-06-01 08:00:00', $timezone);

        $mediaA = $this->makeMediaFixture(1, 'index-1.jpg', $base, 52.52, 13.405);
        $mediaB = $this->makeMediaFixture(
            2,
            'index-2.jpg',
            $base->add(new DateInterval('PT15M')),
            52.5201,
            13.4051,
        );
        $mediaC = $this->makeMediaFixture(
            3,
            'index-3.jpg',
            $base->add(new DateInterval('PT2H')),
            52.521,
            13.406,
        );

        $staypoints = [
            [
                'lat'   => 52.52005,
                'lon'   => 13.40505,
                'start' => $base->getTimestamp(),
                'end'   => $base->add(new DateInterval('PT30M'))->getTimestamp(),
                'dwell' => 1800,
            ],
            [
                'lat'   => 52.5209,
                'lon'   => 13.4059,
                'start' => $base->add(new DateInterval('PT90M'))->getTimestamp(),
                'end'   => $base->add(new DateInterval('PT3H'))->getTimestamp(),
                'dwell' => 5400,
            ],
        ];

        $index = StaypointIndex::build('2024-06-01', $staypoints, [$mediaA, $mediaB, $mediaC]);

        $firstKey  = StaypointIndex::createKeyFromStaypoint('2024-06-01', $staypoints[0]);
        $secondKey = StaypointIndex::createKeyFromStaypoint('2024-06-01', $staypoints[1]);

        self::assertSame($firstKey, $index->get($mediaA));
        self::assertSame($firstKey, $index->get($mediaB));
        self::assertSame($secondKey, $index->get($mediaC));

        $counts = $index->getCounts();
        self::assertSame(2, $counts[$firstKey]);
        self::assertSame(1, $counts[$secondKey]);
    }
}
