<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

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
        $detector = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: 60.0,
            minItemsPerDay: 2,
        );

        $home = [
            'lat' => 52.5,
            'lon' => 13.4,
            'radius_km' => 15.0,
            'country' => 'de',
            'timezone_offset' => 60,
        ];

        $media = $this->createMock(Media::class);

        $days = [
            '2024-02-29' => $this->makeDaySummary('2024-02-29', false, [$media], 10.0, 80.0, 1, hasAirport: true),
            '2024-03-01' => $this->makeDaySummary('2024-03-01', true, [$media], 30.0, 120.0, 4),
            '2024-03-02' => $this->makeDaySummary('2024-03-02', false, [], 5.0, 40.0, 1),
            '2024-03-03' => $this->makeDaySummary('2024-03-03', true, [$media], 28.0, 140.0, 4),
            '2024-03-04' => $this->makeDaySummary('2024-03-04', false, [$media], 12.0, 90.0, 2, hasAirport: true),
        ];

        $runs = $detector->detectVacationRuns($days, $home);

        self::assertCount(1, $runs);
        self::assertSame(
            ['2024-02-29', '2024-03-01', '2024-03-02', '2024-03-03', '2024-03-04'],
            $runs[0]
        );
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
        bool $isSynthetic = false,
    ): array {
        return [
            'date' => $date,
            'members' => $gpsMembers,
            'gpsMembers' => $gpsMembers,
            'maxDistanceKm' => $maxDistanceKm,
            'avgDistanceKm' => $avgDistanceKm,
            'travelKm' => 0.0,
            'countryCodes' => [],
            'timezoneOffsets' => [],
            'localTimezoneIdentifier' => 'Europe/Berlin',
            'localTimezoneOffset' => 60,
            'tourismHits' => 0,
            'poiSamples' => 0,
            'tourismRatio' => 0.0,
            'hasAirportPoi' => $hasAirport,
            'weekday' => 5,
            'photoCount' => $photoCount,
            'densityZ' => 0.0,
            'isAwayCandidate' => $baseAway,
            'sufficientSamples' => true,
            'spotClusters' => [],
            'spotNoise' => [],
            'spotCount' => 0,
            'spotNoiseSamples' => 0,
            'spotDwellSeconds' => 0,
            'staypoints' => [],
            'baseLocation' => null,
            'baseAway' => $baseAway,
            'awayByDistance' => $baseAway,
            'firstGpsMedia' => null,
            'lastGpsMedia' => null,
            'isSynthetic' => $isSynthetic,
        ];
    }
}
