<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Service\RunDetector;
use MagicSunday\Memories\Clusterer\Support\StaypointIndex;
use MagicSunday\Memories\Clusterer\Service\TransportDayExtender;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @covers \MagicSunday\Memories\Clusterer\Service\RunDetector
 */
final class RunDetectorTest extends TestCase
{
    #[Test]
    public function detectVacationRunsSplitsWhenAnchorsVanishButKeepsTransportEdges(): void
    {
        $transportExtender = new TransportDayExtender();
        $detector          = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: 60.0,
            minItemsPerDay: 2,
        );

        $home = [
            'lat'             => 52.5,
            'lon'             => 13.4,
            'radius_km'       => 15.0,
            'country'         => 'de',
            'timezone_offset' => 60,
            'centers'         => [[
                'lat'           => 52.5,
                'lon'           => 13.4,
                'radius_km'     => 15.0,
                'member_count'  => 0,
                'dwell_seconds' => 0,
            ]],
        ];

        $transitOut = $this->makeMediaFixture(
            500,
            'depart.jpg',
            new DateTimeImmutable('2024-02-29 09:00:00', new DateTimeZone('Europe/Berlin')),
            50.1109,
            8.6821,
        );
        $tripMedia = $this->makeMediaFixture(
            501,
            'trip.jpg',
            new DateTimeImmutable('2024-03-01 12:00:00', new DateTimeZone('Europe/Berlin')),
            47.3769,
            8.5417,
        );
        $returnMedia = $this->makeMediaFixture(
            502,
            'return.jpg',
            new DateTimeImmutable('2024-03-04 18:00:00', new DateTimeZone('Europe/Berlin')),
            52.5201,
            13.4049,
        );

        $days = [
            '2024-02-29' => $this->makeDaySummary('2024-02-29', false, [$transitOut], 10.0, 80.0, 1, hasAirport: false, hasHighSpeedTransit: true),
            '2024-03-01' => $this->makeDaySummary('2024-03-01', true, [$tripMedia], 30.0, 120.0, 4),
            '2024-03-02' => $this->makeDaySummary('2024-03-02', false, [], 5.0, 40.0, 1),
            '2024-03-03' => $this->makeDaySummary('2024-03-03', true, [$tripMedia], 28.0, 140.0, 4),
            '2024-03-04' => $this->makeDaySummary('2024-03-04', false, [$returnMedia], 12.0, 90.0, 2, hasAirport: false, hasHighSpeedTransit: true),
        ];

        $runs = $detector->detectVacationRuns($days, $home);

        self::assertCount(2, $runs);
        self::assertSame(['2024-02-29', '2024-03-01'], $runs[0]);
        self::assertSame(['2024-03-03', '2024-03-04'], $runs[1]);
    }

    #[Test]
    public function respectsSecondaryHomeCenterWhenEvaluatingCandidates(): void
    {
        $transportExtender = new TransportDayExtender();
        $detector          = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: 60.0,
            minItemsPerDay: 2,
        );

        $home = [
            'lat'             => 52.5,
            'lon'             => 13.4,
            'radius_km'       => 15.0,
            'country'         => 'de',
            'timezone_offset' => 60,
            'centers'         => [[
                'lat'           => 52.5,
                'lon'           => 13.4,
                'radius_km'     => 15.0,
                'member_count'  => 0,
                'dwell_seconds' => 0,
            ], [
                'lat'           => 48.1371,
                'lon'           => 11.5754,
                'radius_km'     => 8.0,
                'member_count'  => 0,
                'dwell_seconds' => 0,
            ]],
        ];

        $munichMedia = $this->makeMediaFixture(
            900,
            'munich-home.jpg',
            new DateTimeImmutable('2024-04-15 09:00:00', new DateTimeZone('Europe/Berlin')),
            48.1372,
            11.5755,
        );

        $days = [
            '2024-04-15' => $this->makeDaySummary('2024-04-15', false, [$munichMedia], 25.0, 40.0, 3),
        ];

        $runs = $detector->detectVacationRuns($days, $home);

        self::assertSame([], $runs);
    }

    #[Test]
    public function detectsTransitPairAndHomeStaypointBoundaries(): void
    {
        $transportExtender = new TransportDayExtender();
        $detector          = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: 60.0,
            minItemsPerDay: 2,
        );

        $home = [
            'lat'             => 52.5,
            'lon'             => 13.4,
            'radius_km'       => 15.0,
            'country'         => 'de',
            'timezone_offset' => 60,
            'centers'         => [[
                'lat'           => 52.5,
                'lon'           => 13.4,
                'radius_km'     => 15.0,
                'member_count'  => 0,
                'dwell_seconds' => 0,
            ]],
        ];

        $transitMediaA = $this->makeMediaFixture(
            101,
            'transit-a.jpg',
            new DateTimeImmutable('2024-06-01 08:00:00', new DateTimeZone('Europe/Berlin')),
            50.1109,
            8.6821,
        );
        $transitMediaB = $this->makeMediaFixture(
            102,
            'transit-b.jpg',
            new DateTimeImmutable('2024-06-02 09:00:00', new DateTimeZone('Europe/Berlin')),
            47.3769,
            8.5417,
        );
        $parisMedia = $this->makeMediaFixture(
            103,
            'paris.jpg',
            new DateTimeImmutable('2024-06-03 12:00:00', new DateTimeZone('Europe/Paris')),
            48.8566,
            2.3522,
        );
        $homeMedia = $this->makeMediaFixture(
            104,
            'home-return.jpg',
            new DateTimeImmutable('2024-06-04 19:00:00', new DateTimeZone('Europe/Berlin')),
            52.5,
            13.4,
        );

        $days = [
            '2024-06-01' => $this->makeDaySummary(
                '2024-06-01',
                false,
                [$transitMediaA],
                15.0,
                180.0,
                1,
                hasAirport: false,
                hasHighSpeedTransit: false,
                isSynthetic: false,
                overrides: [
                    'dominantStaypoints' => [],
                    'transitRatio'       => 0.75,
                    'avgSpeedKmh'        => 110.0,
                    'maxSpeedKmh'        => 200.0,
                ],
            ),
            '2024-06-02' => $this->makeDaySummary(
                '2024-06-02',
                false,
                [$transitMediaB],
                18.0,
                190.0,
                2,
                hasAirport: false,
                hasHighSpeedTransit: false,
                isSynthetic: false,
                overrides: [
                    'dominantStaypoints' => [],
                    'transitRatio'       => 0.72,
                    'avgSpeedKmh'        => 105.0,
                    'maxSpeedKmh'        => 195.0,
                ],
            ),
            '2024-06-03' => $this->makeDaySummary(
                '2024-06-03',
                false,
                [$parisMedia],
                45.0,
                210.0,
                3,
                hasAirport: false,
                hasHighSpeedTransit: false,
                isSynthetic: false,
                overrides: [
                    'dominantStaypoints' => [
                        $this->makeDominantStaypoint('stay-2024-06-03', 48.8566, 2.3522),
                    ],
                    'transitRatio'       => 0.25,
                    'avgSpeedKmh'        => 70.0,
                    'maxSpeedKmh'        => 120.0,
                ],
            ),
            '2024-06-04' => $this->makeDaySummary(
                '2024-06-04',
                false,
                [$homeMedia],
                8.0,
                40.0,
                4,
                hasAirport: false,
                hasHighSpeedTransit: false,
                isSynthetic: false,
                overrides: [
                    'dominantStaypoints' => [
                        $this->makeDominantStaypoint('stay-2024-06-04', 52.5, 13.4),
                    ],
                    'transitRatio'       => 0.1,
                    'avgSpeedKmh'        => 35.0,
                    'maxSpeedKmh'        => 60.0,
                ],
            ),
        ];

        $runs = $detector->detectVacationRuns($days, $home);

        self::assertCount(1, $runs);
        self::assertSame(['2024-06-01', '2024-06-02', '2024-06-03'], $runs[0]);
    }

    #[Test]
    public function extendsLeanDayNextToTransitHeavyNeighbours(): void
    {
        $transportExtender = new TransportDayExtender();
        $detector          = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: 60.0,
            minItemsPerDay: 2,
        );

        $home = [
            'lat'             => 52.5,
            'lon'             => 13.4,
            'radius_km'       => 15.0,
            'country'         => 'de',
            'timezone_offset' => 60,
            'centers'         => [[
                'lat'           => 52.5,
                'lon'           => 13.4,
                'radius_km'     => 15.0,
                'member_count'  => 0,
                'dwell_seconds' => 0,
            ]],
        ];

        $leanMedia = $this->makeMediaFixture(
            201,
            'lean-day.jpg',
            new DateTimeImmutable('2024-07-09 07:30:00', new DateTimeZone('Europe/Berlin')),
            50.0,
            8.0,
        );
        $transitAnchor = $this->makeMediaFixture(
            202,
            'transit-anchor.jpg',
            new DateTimeImmutable('2024-07-10 10:00:00', new DateTimeZone('Europe/Berlin')),
            48.8566,
            2.3522,
        );
        $homeReturn = $this->makeMediaFixture(
            203,
            'home-after.jpg',
            new DateTimeImmutable('2024-07-11 18:00:00', new DateTimeZone('Europe/Berlin')),
            52.5,
            13.4,
        );

        $days = [
            '2024-07-09' => $this->makeDaySummary(
                '2024-07-09',
                false,
                [$leanMedia],
                6.0,
                30.0,
                1,
                hasAirport: false,
                hasHighSpeedTransit: false,
                isSynthetic: false,
                overrides: [
                    'dominantStaypoints' => [],
                    'transitRatio'       => 0.1,
                    'avgSpeedKmh'        => 45.0,
                    'maxSpeedKmh'        => 70.0,
                ],
            ),
            '2024-07-10' => $this->makeDaySummary(
                '2024-07-10',
                false,
                [$transitAnchor],
                55.0,
                220.0,
                3,
                hasAirport: false,
                hasHighSpeedTransit: false,
                isSynthetic: false,
                overrides: [
                    'dominantStaypoints' => [
                        $this->makeDominantStaypoint('stay-2024-07-10', 48.8566, 2.3522),
                    ],
                    'transitRatio'       => 0.82,
                    'avgSpeedKmh'        => 115.0,
                    'maxSpeedKmh'        => 210.0,
                ],
            ),
            '2024-07-11' => $this->makeDaySummary(
                '2024-07-11',
                false,
                [$homeReturn],
                10.0,
                35.0,
                4,
                hasAirport: false,
                hasHighSpeedTransit: false,
                isSynthetic: false,
                overrides: [
                    'dominantStaypoints' => [
                        $this->makeDominantStaypoint('stay-2024-07-11', 52.5, 13.4),
                    ],
                    'transitRatio'       => 0.05,
                    'avgSpeedKmh'        => 30.0,
                    'maxSpeedKmh'        => 55.0,
                ],
            ),
        ];

        $runs = $detector->detectVacationRuns($days, $home);

        self::assertCount(1, $runs);
        self::assertSame(['2024-07-09', '2024-07-10'], $runs[0]);
    }

    /**
     * @param list<Media> $gpsMembers
     */
    private function makeDaySummary(
        string $date,
        bool $baseAway,
        array $gpsMembers,
        float $avgDistanceKm,
        float $maxDistanceKm,
        int $photoCount,
        bool $hasAirport = false,
        bool $hasHighSpeedTransit = false,
        bool $isSynthetic = false,
        array $overrides = [],
    ): array {
        $summary = [
            'date'                    => $date,
            'members'                 => $gpsMembers,
            'gpsMembers'              => $gpsMembers,
            'maxDistanceKm'           => $maxDistanceKm,
            'avgDistanceKm'           => $avgDistanceKm,
            'travelKm'                => $hasHighSpeedTransit ? 180.0 : 0.0,
            'maxSpeedKmh'             => $hasHighSpeedTransit ? 220.0 : 0.0,
            'avgSpeedKmh'             => $hasHighSpeedTransit ? 160.0 : 0.0,
            'hasHighSpeedTransit'     => $hasHighSpeedTransit,
            'countryCodes'            => [],
            'timezoneOffsets'         => [],
            'localTimezoneIdentifier' => 'Europe/Berlin',
            'localTimezoneOffset'     => 60,
            'tourismHits'             => 0,
            'poiSamples'              => 0,
            'tourismRatio'            => 0.0,
            'hasAirportPoi'           => $hasAirport,
            'weekday'                 => 5,
            'photoCount'              => $photoCount,
            'densityZ'                => 0.0,
            'isAwayCandidate'         => $baseAway,
            'sufficientSamples'       => true,
            'spotClusters'            => [],
            'spotNoise'               => [],
            'spotCount'               => 0,
            'spotNoiseSamples'        => 0,
            'spotDwellSeconds'        => 0,
            'staypoints'              => [],
            'staypointIndex'          => StaypointIndex::empty(),
            'staypointCounts'         => [],
            'dominantStaypoints'      => [],
            'transitRatio'            => 0.0,
            'poiDensity'              => 0.0,
            'cohortPresenceRatio'     => 0.0,
            'cohortMembers'           => [],
            'baseLocation'            => null,
            'baseAway'                => $baseAway,
            'awayByDistance'          => $baseAway,
            'firstGpsMedia'           => null,
            'lastGpsMedia'            => null,
            'isSynthetic'             => $isSynthetic,
        ];

        if ($overrides !== []) {
            foreach ($overrides as $key => $value) {
                $summary[$key] = $value;
            }
        }

        return $summary;
    }

    /**
     * @return array{key:string,lat:float,lon:float,start:int,end:int,dwellSeconds:int,memberCount:int}
     */
    private function makeDominantStaypoint(string $key, float $lat, float $lon, int $dwellSeconds = 3600, int $memberCount = 3): array
    {
        return [
            'key'          => $key,
            'lat'          => $lat,
            'lon'          => $lon,
            'start'        => 0,
            'end'          => $dwellSeconds,
            'dwellSeconds' => $dwellSeconds,
            'memberCount'  => $memberCount,
        ];
    }
}
