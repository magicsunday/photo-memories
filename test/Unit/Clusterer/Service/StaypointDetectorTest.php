<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Service\StaypointDetector;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class StaypointDetectorTest extends TestCase
{
    #[Test]
    public function detectsStaypointWhenDwellExceedsThreshold(): void
    {
        $detector = new StaypointDetector();
        $timezone = new DateTimeZone('UTC');
        $start    = new DateTimeImmutable('2024-05-10 09:00:00', $timezone);

        $media   = [];
        $media[] = $this->makeMediaFixture(1, 'staypoint-1.jpg', $start, 48.2082, 16.3738);
        $media[] = $this->makeMediaFixture(
            2,
            'staypoint-2.jpg',
            $start->add(new DateInterval('PT30M')),
            48.2083,
            16.3737,
        );
        $media[] = $this->makeMediaFixture(
            3,
            'staypoint-3.jpg',
            $start->add(new DateInterval('PT2H')),
            48.2081,
            16.3739,
        );

        $staypoints = $detector->detect($media);

        self::assertCount(1, $staypoints);
        $first = $staypoints[0];

        self::assertSame($media[0]->getTakenAt()?->getTimestamp(), $first['start']);
        self::assertSame($media[2]->getTakenAt()?->getTimestamp(), $first['end']);
        self::assertSame(7200, $first['dwell']);
        self::assertEqualsWithDelta(48.2082, $first['lat'], 1.0e-4);
        self::assertEqualsWithDelta(16.3738, $first['lon'], 1.0e-4);
    }

    #[Test]
    public function ignoresShortVisits(): void
    {
        $detector = new StaypointDetector();
        $timezone = new DateTimeZone('UTC');
        $start    = new DateTimeImmutable('2024-05-11 12:00:00', $timezone);

        $media   = [];
        $media[] = $this->makeMediaFixture(10, 'short-visit-1.jpg', $start, 51.0504, 13.7373);
        $media[] = $this->makeMediaFixture(
            11,
            'short-visit-2.jpg',
            $start->add(new DateInterval('PT14M')),
            51.0505,
            13.7374,
        );

        self::assertSame([], $detector->detect($media));
    }

    #[Test]
    public function usesDbscanFallbackWhenSequentialThresholdsAreTooStrict(): void
    {
        $detector = new StaypointDetector(
            staypointRadiusKm: 0.15,
            minDwellMinutes: 30,
            dbscanHelper: new GeoDbscanHelper(),
            fallbackRadiusKm: 0.18,
            fallbackMinSamples: 3,
            fallbackMinDwellMinutes: 20,
        );

        $timezone = new DateTimeZone('UTC');
        $start    = new DateTimeImmutable('2024-05-12 09:00:00', $timezone);

        $media   = [];
        $media[] = $this->makeMediaFixture(21, 'fallback-1.jpg', $start, 40.7127, -74.0060);
        $media[] = $this->makeMediaFixture(
            22,
            'fallback-2.jpg',
            $start->add(new DateInterval('PT10M')),
            40.7128,
            -74.0061,
        );
        $media[] = $this->makeMediaFixture(
            23,
            'fallback-3.jpg',
            $start->add(new DateInterval('PT25M')),
            40.7129,
            -74.0062,
        );

        $staypoints = $detector->detect($media);

        self::assertCount(1, $staypoints);
        self::assertSame($media[0]->getTakenAt()?->getTimestamp(), $staypoints[0]['start']);
        self::assertSame($media[2]->getTakenAt()?->getTimestamp(), $staypoints[0]['end']);
    }

    #[Test]
    public function adaptsThresholdsForDenseUrbanDays(): void
    {
        $detector = new StaypointDetector();
        $timezone = new DateTimeZone('UTC');
        $start    = new DateTimeImmutable('2024-06-05 18:00:00', $timezone);

        $media   = [];
        $media[] = $this->makeMediaFixture(40, 'urban-1.jpg', $start, 48.8566, 2.3522);
        $media[] = $this->makeMediaFixture(41, 'urban-2.jpg', $start->add(new DateInterval('PT5M')), 48.8567, 2.3523);
        $media[] = $this->makeMediaFixture(42, 'urban-3.jpg', $start->add(new DateInterval('PT12M')), 48.85665, 2.35225);
        $media[] = $this->makeMediaFixture(43, 'urban-4.jpg', $start->add(new DateInterval('PT17M')), 48.8566, 2.35228);

        $staypoints = $detector->detect(
            $media,
            [
                'travelKm'    => 2.0,
                'spotCount'   => 6,
                'spotDensity' => 6.0,
            ],
        );

        self::assertCount(1, $staypoints);
        self::assertSame($media[0]->getTakenAt()?->getTimestamp(), $staypoints[0]['start']);
        self::assertSame($media[3]->getTakenAt()?->getTimestamp(), $staypoints[0]['end']);
        self::assertSame(17 * 60, $staypoints[0]['dwell']);
    }

    #[Test]
    public function stretchesRadiusAndDwellForSparseRuralTravel(): void
    {
        $detector = new StaypointDetector();
        $timezone = new DateTimeZone('UTC');
        $start    = new DateTimeImmutable('2024-06-06 09:00:00', $timezone);

        $context = [
            'travelKm'    => 120.0,
            'spotCount'   => 1,
            'spotDensity' => 0.02,
        ];

        $short = [];
        $short[] = $this->makeMediaFixture(50, 'rural-short-1.jpg', $start, 60.0000, 10.0000);
        $short[] = $this->makeMediaFixture(51, 'rural-short-2.jpg', $start->add(new DateInterval('PT8M')), 60.0025, 10.0025);
        $short[] = $this->makeMediaFixture(52, 'rural-short-3.jpg', $start->add(new DateInterval('PT16M')), 60.0027, 10.0027);

        self::assertSame([], $detector->detect($short, $context));

        $long = [];
        $long[] = $this->makeMediaFixture(60, 'rural-long-1.jpg', $start, 60.0000, 10.0000);
        $long[] = $this->makeMediaFixture(61, 'rural-long-2.jpg', $start->add(new DateInterval('PT18M')), 60.0025, 10.0025);
        $long[] = $this->makeMediaFixture(62, 'rural-long-3.jpg', $start->add(new DateInterval('PT35M')), 60.0027, 10.0027);

        $staypoints = $detector->detect($long, $context);

        self::assertCount(1, $staypoints);
        self::assertSame($long[0]->getTakenAt()?->getTimestamp(), $staypoints[0]['start']);
        self::assertSame($long[2]->getTakenAt()?->getTimestamp(), $staypoints[0]['end']);
        self::assertSame(35 * 60, $staypoints[0]['dwell']);
    }
}
