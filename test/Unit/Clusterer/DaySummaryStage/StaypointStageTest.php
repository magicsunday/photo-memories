<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\DaySummaryStage;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\DaySummaryStage\GpsMetricsStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\StaypointStage;
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\StaypointDetector;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Clusterer\Support\StaypointIndex;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class StaypointStageTest extends TestCase
{
    #[Test]
    public function enrichesSummariesWithStaypointAggregates(): void
    {
        $timezoneResolver = new TimezoneResolver('Europe/Berlin');
        $initialStage     = new InitializationStage($timezoneResolver, new PoiClassifier(), 'Europe/Berlin');
        $gpsStage         = new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector(), 1.0, 3, 2);
        $staypointStage   = new StaypointStage();

        $home = [
            'lat'             => 48.137,
            'lon'             => 11.575,
            'radius_km'       => 5.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $items   = [];
        $start   = new DateTimeImmutable('2024-04-15 09:00:00', new DateTimeZone('Europe/Berlin'));
        $items[] = $this->makeMediaFixture(10, 'stay-1.jpg', $start, 48.1371, 11.5754);
        $items[] = $this->makeMediaFixture(
            11,
            'stay-2.jpg',
            $start->add(new DateInterval('PT30M')),
            48.1372,
            11.5753,
        );
        $items[] = $this->makeMediaFixture(
            12,
            'stay-3.jpg',
            $start->add(new DateInterval('PT1H')),
            48.13715,
            11.57525,
        );

        $initial = $initialStage->process($items, $home);
        $gps     = $gpsStage->process($initial, $home);

        $dayKey               = '2024-04-15';
        $gps[$dayKey]['poiSamples'] = 1;

        $result  = $staypointStage->process($gps, $home);
        $summary = $result[$dayKey];

        self::assertArrayHasKey('staypointIndex', $summary);
        self::assertInstanceOf(StaypointIndex::class, $summary['staypointIndex']);

        $index = $summary['staypointIndex'];
        $key   = $index->get($items[0]);

        self::assertNotNull($key);
        self::assertArrayHasKey($key, $summary['staypointCounts']);
        self::assertSame(3, $summary['staypointCounts'][$key]);

        self::assertNotSame([], $summary['dominantStaypoints']);
        self::assertSame($key, $summary['dominantStaypoints'][0]['key']);
        self::assertSame(3, $summary['dominantStaypoints'][0]['memberCount']);

        self::assertGreaterThanOrEqual(0.0, $summary['transitRatio']);
        self::assertLessThanOrEqual(1.0, $summary['transitRatio']);
        self::assertGreaterThan(0.0, $summary['poiDensity']);
    }
}
