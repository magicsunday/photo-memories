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
    public function detectVacationRunsIncludesBridgeAndTransportDays(): void
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

        $media = $this->createMock(Media::class);

        $days = [
            '2024-02-29' => $this->makeDaySummary('2024-02-29', false, [$media], 10.0, 80.0, 1, hasAirport: false, hasHighSpeedTransit: true),
            '2024-03-01' => $this->makeDaySummary('2024-03-01', true, [$media], 30.0, 120.0, 4),
            '2024-03-02' => $this->makeDaySummary('2024-03-02', false, [], 5.0, 40.0, 1),
            '2024-03-03' => $this->makeDaySummary('2024-03-03', true, [$media], 28.0, 140.0, 4),
            '2024-03-04' => $this->makeDaySummary('2024-03-04', false, [$media], 12.0, 90.0, 2, hasAirport: false, hasHighSpeedTransit: true),
        ];

        $runs = $detector->detectVacationRuns($days, $home);

        self::assertCount(1, $runs);
        self::assertSame(
            ['2024-02-29', '2024-03-01', '2024-03-02', '2024-03-03', '2024-03-04'],
            $runs[0]
        );
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
    ): array {
        return [
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
            'cohortPresenceRatio'     => 0.0,
            'cohortMembers'           => [],
            'baseLocation'            => null,
            'baseAway'                => $baseAway,
            'awayByDistance'          => $baseAway,
            'firstGpsMedia'           => null,
            'lastGpsMedia'            => null,
            'isSynthetic'             => $isSynthetic,
        ];
    }
}
