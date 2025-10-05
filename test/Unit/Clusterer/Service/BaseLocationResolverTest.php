<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Service;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Service\BaseLocationResolver;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class BaseLocationResolverTest extends TestCase
{
    #[Test]
    public function selectsStaypointBaseWhenCandidateOvernight(): void
    {
        $resolver = new BaseLocationResolver();
        $timezone = new DateTimeZone('Europe/Berlin');

        $startMedia = $this->makeMediaFixture(
            100,
            'staypoint-start.jpg',
            new DateTimeImmutable('2024-07-01 21:30:00', $timezone),
            48.1371,
            11.5754,
        );
        $endMedia = $this->makeMediaFixture(
            101,
            'staypoint-end.jpg',
            new DateTimeImmutable('2024-07-02 07:15:00', $timezone),
            48.1372,
            11.5755,
        );

        $summary = [
            'date'       => '2024-07-01',
            'staypoints' => [[
                'lat'   => 48.1371,
                'lon'   => 11.5754,
                'start' => $startMedia->getTakenAt()?->getTimestamp() ?? 0,
                'end'   => $endMedia->getTakenAt()?->getTimestamp() ?? 0,
                'dwell' => 35000,
            ]],
            'firstGpsMedia' => $startMedia,
            'lastGpsMedia'  => $endMedia,
            'gpsMembers'    => [$startMedia, $endMedia],
        ];

        $nextSummary = [
            'date'          => '2024-07-02',
            'staypoints'    => [],
            'firstGpsMedia' => null,
        ];

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $result = $resolver->resolve($summary, $nextSummary, $home, $timezone);

        self::assertNotNull($result);
        self::assertSame('staypoint', $result['source']);
        self::assertGreaterThan($home['radius_km'], $result['distance_km']);
        self::assertEqualsWithDelta(48.1371, $result['lat'], 1.0e-4);
        self::assertEqualsWithDelta(11.5754, $result['lon'], 1.0e-4);
    }

    #[Test]
    public function prefersSleepProxyPairWhenConsecutiveNightsAway(): void
    {
        $resolver = new BaseLocationResolver();
        $timezone = new DateTimeZone('Europe/Berlin');

        $lastMedia = $this->makeMediaFixture(
            200,
            'sleep-last.jpg',
            new DateTimeImmutable('2024-08-05 22:00:00', $timezone),
            40.4168,
            -3.7038,
        );
        $nextFirst = $this->makeMediaFixture(
            201,
            'sleep-next.jpg',
            new DateTimeImmutable('2024-08-06 07:00:00', $timezone),
            40.4169,
            -3.7037,
        );

        $summary = [
            'date'          => '2024-08-05',
            'staypoints'    => [],
            'firstGpsMedia' => $lastMedia,
            'lastGpsMedia'  => $lastMedia,
            'gpsMembers'    => [$lastMedia],
        ];

        $nextSummary = [
            'date'          => '2024-08-06',
            'staypoints'    => [],
            'firstGpsMedia' => $nextFirst,
        ];

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 10.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $result = $resolver->resolve($summary, $nextSummary, $home, $timezone);

        self::assertNotNull($result);
        self::assertSame('sleep_proxy_pair', $result['source']);
        self::assertGreaterThan($home['radius_km'], $result['distance_km']);
        self::assertEqualsWithDelta(40.41685, $result['lat'], 5.0e-5);
        self::assertEqualsWithDelta(-3.70375, $result['lon'], 5.0e-5);
    }
}
