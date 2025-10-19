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
    public function bridgesMissingMediaDayWhenStaypointDwellIsStrong(): void
    {
        $transportExtender = new TransportDayExtender();
        $detector          = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: 60.0,
            minItemsPerDay: 3,
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

        $startMedia = $this->makeMediaFixture(
            601,
            'run-start.jpg',
            new DateTimeImmutable('2024-08-10 09:00:00', new DateTimeZone('Europe/Berlin')),
            41.9028,
            12.4964,
        );
        $endMedia = $this->makeMediaFixture(
            602,
            'run-end.jpg',
            new DateTimeImmutable('2024-08-12 19:30:00', new DateTimeZone('Europe/Berlin')),
            45.4642,
            9.1900,
        );

        $bridgeStart = new DateTimeImmutable('2024-08-11 06:30:00', new DateTimeZone('Europe/Berlin'));
        $bridgeEnd   = new DateTimeImmutable('2024-08-11 22:15:00', new DateTimeZone('Europe/Berlin'));

        $bridgeSummary = $this->makeDaySummary('2024-08-11', false, [$startMedia], 5.0, 45.0, 1);
        $bridgeSummary['members']            = [];
        $bridgeSummary['gpsMembers']         = [];
        $bridgeSummary['photoCount']         = 0;
        $bridgeSummary['sufficientSamples']  = false;
        $bridgeSummary['staypoints']         = [[
            'lat'   => 45.4642,
            'lon'   => 9.1900,
            'start' => $bridgeStart->getTimestamp(),
            'end'   => $bridgeEnd->getTimestamp(),
            'dwell' => $bridgeEnd->getTimestamp() - $bridgeStart->getTimestamp(),
        ]];
        $bridgeSummary['staypointIndex']     = StaypointIndex::empty();
        $bridgeSummary['staypointCounts']    = ['2024-08-11:bridge' => 6];
        $bridgeSummary['dominantStaypoints'] = [[
            'key'          => 'bridge',
            'lat'          => 45.4642,
            'lon'          => 9.1900,
            'start'        => $bridgeStart->getTimestamp(),
            'end'          => $bridgeEnd->getTimestamp(),
            'dwellSeconds' => $bridgeEnd->getTimestamp() - $bridgeStart->getTimestamp(),
            'memberCount'  => 0,
        ]];
        $bridgeSummary['baseAway']           = false;
        $bridgeSummary['awayByDistance']     = false;

        $days = [
            '2024-08-10' => $this->makeDaySummary('2024-08-10', true, [$startMedia], 140.0, 220.0, 6),
            '2024-08-11' => $bridgeSummary,
            '2024-08-12' => $this->makeDaySummary('2024-08-12', true, [$endMedia], 135.0, 210.0, 5),
        ];

        $runs = $detector->detectVacationRuns($days, $home);

        self::assertSame([
            ['2024-08-10', '2024-08-11', '2024-08-12'],
        ], $runs);
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

    #[Test]
    public function longRunAdoptsSoftDistanceProfileAndBridging(): void
    {
        $transportExtender = new TransportDayExtender(
            transitRatioThreshold: 0.65,
            transitSpeedThreshold: 100.0,
            leanPhotoThreshold: 2,
            maxLeanBridgeDays: 3,
            minLeanBridgeDistanceKm: 10.0,
        );
        $detector          = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: 140.0,
            minItemsPerDay: 5,
            minAwayDistanceProfiles: [
                [
                    'distance_km'            => 60.0,
                    'min_total_member_count' => 500,
                ],
            ],
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
                'member_count'  => 40,
                'dwell_seconds' => 0,
            ]],
        ];

        $days   = [];
        $anchor = new DateTimeImmutable('2024-08-01 12:00:00', new DateTimeZone('Europe/Berlin'));
        for ($i = 0; $i < 10; ++$i) {
            $current   = $anchor->modify(sprintf('+%d days', $i));
            $date      = $current->format('Y-m-d');
            $media     = $this->makeMediaFixture(
                600 + $i,
                sprintf('base-%02d.jpg', $i + 1),
                $current,
                46.94809,
                7.44744,
            );
            $days[$date] = $this->makeDaySummary($date, true, [$media], 120.0, 180.0, 3);
        }

        $softStart  = $anchor->modify('+10 days');
        $softOne    = $this->makeMediaFixture(700, 'soft-a.jpg', $softStart, 45.4215, 11.8870);
        $softOneKey = $softStart->format('Y-m-d');
        $days[$softOneKey] = $this->makeDaySummary($softOneKey, false, [$softOne], 45.0, 70.0, 2);

        $bridgeDate  = $softStart->modify('+1 day');
        $bridgeMedia = $this->makeMediaFixture(701, 'bridge.jpg', $bridgeDate, 45.5, 11.5);
        $bridgeKey   = $bridgeDate->format('Y-m-d');
        $days[$bridgeKey] = $this->makeDaySummary($bridgeKey, false, [$bridgeMedia], 18.0, 30.0, 1);

        $softEnd    = $bridgeDate->modify('+1 day');
        $softTwo    = $this->makeMediaFixture(702, 'soft-b.jpg', $softEnd, 45.7610, 12.2460);
        $softTwoKey = $softEnd->format('Y-m-d');
        $days[$softTwoKey] = $this->makeDaySummary($softTwoKey, false, [$softTwo], 48.0, 75.0, 2);

        $runs = $detector->detectVacationRuns($days, $home);

        $expected = [
            '2024-08-01',
            '2024-08-02',
            '2024-08-03',
            '2024-08-04',
            '2024-08-05',
            '2024-08-06',
            '2024-08-07',
            '2024-08-08',
            '2024-08-09',
            '2024-08-10',
            '2024-08-11',
            '2024-08-12',
            '2024-08-13',
        ];

        self::assertCount(1, $runs);
        self::assertSame($expected, $runs[0]);
    }

    #[Test]
    public function shortRunKeepsStrictDistanceProfile(): void
    {
        $transportExtender = new TransportDayExtender();
        $detector          = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: 140.0,
            minItemsPerDay: 4,
            minAwayDistanceProfiles: [
                [
                    'distance_km'            => 60.0,
                    'min_total_member_count' => 500,
                ],
            ],
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
                'member_count'  => 40,
                'dwell_seconds' => 0,
            ]],
        ];

        $days   = [];
        $anchor = new DateTimeImmutable('2024-09-01 12:00:00', new DateTimeZone('Europe/Berlin'));
        for ($i = 0; $i < 5; ++$i) {
            $current   = $anchor->modify(sprintf('+%d days', $i));
            $date      = $current->format('Y-m-d');
            $media     = $this->makeMediaFixture(
                800 + $i,
                sprintf('short-%02d.jpg', $i + 1),
                $current,
                46.2044,
                6.1432,
            );
            $days[$date] = $this->makeDaySummary($date, true, [$media], 115.0, 165.0, 3);
        }

        $softDate    = $anchor->modify('+5 days');
        $softMedia   = $this->makeMediaFixture(900, 'soft-short.jpg', $softDate, 45.4642, 9.1900);
        $softKey     = $softDate->format('Y-m-d');
        $days[$softKey] = $this->makeDaySummary($softKey, false, [$softMedia], 38.0, 70.0, 3);

        $runs = $detector->detectVacationRuns($days, $home);

        self::assertCount(1, $runs);
        self::assertSame([
            '2024-09-01',
            '2024-09-02',
            '2024-09-03',
            '2024-09-04',
            '2024-09-05',
            '2024-09-06',
        ], $runs[0]);
    }

    #[Test]
    public function centroidDistanceSeedsRunWithoutBaseAwayFlag(): void
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

        $dayOneMedia = $this->makeMediaFixture(
            401,
            'centroid-one.jpg',
            new DateTimeImmutable('2024-05-10 09:00:00', new DateTimeZone('Europe/Berlin')),
            41.9028,
            12.4964,
        );
        $dayTwoMedia = $this->makeMediaFixture(
            402,
            'centroid-two.jpg',
            new DateTimeImmutable('2024-05-11 11:00:00', new DateTimeZone('Europe/Berlin')),
            43.7696,
            11.2558,
        );

        $days = [
            '2024-05-10' => $this->makeDaySummary('2024-05-10', false, [$dayOneMedia], 90.0, 180.0, 3),
            '2024-05-11' => $this->makeDaySummary('2024-05-11', false, [$dayTwoMedia], 85.0, 175.0, 3),
        ];

        $runs = $detector->detectVacationRuns($days, $home);

        self::assertSame([
            ['2024-05-10', '2024-05-11'],
        ], $runs);
    }

    #[Test]
    public function transportSignalsSeedAwayWhenDistanceDataIsSparse(): void
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

        $transitMedia = $this->makeMediaFixture(
            501,
            'airport-hop.jpg',
            new DateTimeImmutable('2024-09-04 07:30:00', new DateTimeZone('Europe/Berlin')),
            50.0379,
            8.5622,
        );
        $arrivalMedia = $this->makeMediaFixture(
            502,
            'arrival.jpg',
            new DateTimeImmutable('2024-09-05 13:00:00', new DateTimeZone('Europe/Berlin')),
            52.2297,
            21.0122,
        );

        $days = [
            '2024-09-04' => $this->makeDaySummary(
                '2024-09-04',
                false,
                [$transitMedia],
                45.0,
                160.0,
                2,
                hasAirport: true,
                hasHighSpeedTransit: true,
                overrides: [
                    'gpsMembers'      => [],
                    'members'         => [$transitMedia],
                    'tourismHits'     => 0,
                    'poiSamples'      => 0,
                    'dominantStaypoints' => [],
                ],
            ),
            '2024-09-05' => $this->makeDaySummary('2024-09-05', false, [$arrivalMedia], 80.0, 180.0, 3),
        ];

        $runs = $detector->detectVacationRuns($days, $home);

        self::assertSame([
            ['2024-09-04', '2024-09-05'],
        ], $runs);
    }

    #[Test]
    public function homeDayBetweenTripsSplitsRuns(): void
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

        $awayMediaA = $this->makeMediaFixture(
            601,
            'away-a.jpg',
            new DateTimeImmutable('2024-10-10 10:00:00', new DateTimeZone('Europe/Berlin')),
            41.3851,
            2.1734,
        );
        $homeMedia = $this->makeMediaFixture(
            602,
            'home-break.jpg',
            new DateTimeImmutable('2024-10-11 12:00:00', new DateTimeZone('Europe/Berlin')),
            52.5,
            13.4,
        );
        $awayMediaB = $this->makeMediaFixture(
            603,
            'away-b.jpg',
            new DateTimeImmutable('2024-10-12 09:30:00', new DateTimeZone('Europe/Berlin')),
            48.2082,
            16.3738,
        );

        $days = [
            '2024-10-10' => $this->makeDaySummary('2024-10-10', false, [$awayMediaA], 70.0, 160.0, 4),
            '2024-10-11' => $this->makeDaySummary(
                '2024-10-11',
                false,
                [$homeMedia],
                8.0,
                20.0,
                5,
                overrides: [
                    'baseAway'        => false,
                    'tourismHits'     => 0,
                    'poiSamples'      => 0,
                    'hasHighSpeedTransit' => false,
                    'hasAirportPoi'   => false,
                ],
            ),
            '2024-10-12' => $this->makeDaySummary('2024-10-12', false, [$awayMediaB], 75.0, 170.0, 4),
        ];

        $runs = $detector->detectVacationRuns($days, $home);

        self::assertCount(2, $runs);
        self::assertSame(['2024-10-10'], $runs[0]);
        self::assertSame(['2024-10-12'], $runs[1]);
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
