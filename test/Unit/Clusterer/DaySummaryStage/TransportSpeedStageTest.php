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
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\TransportSpeedStage;
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\MediaMath;
use PHPUnit\Framework\Attributes\Test;

final class TransportSpeedStageTest extends TestCase
{
    #[Test]
    public function computesLegSpeedsAndDetectsHighSpeedTransit(): void
    {
        $timezoneResolver = new TimezoneResolver('UTC');
        $initialStage     = new InitializationStage($timezoneResolver, new PoiClassifier(), 'UTC');
        $speedStage       = new TransportSpeedStage(5.0, 10.0, 100.0);

        $home = [
            'lat'             => 0.0,
            'lon'             => 0.0,
            'radius_km'       => 10.0,
            'country'         => 'xx',
            'timezone_offset' => 0,
        ];

        $base   = new DateTimeImmutable('2024-05-10 08:00:00', new DateTimeZone('UTC'));
        $points = [
            ['id' => 4, 'offset' => 'PT123M', 'lat' => 4.0, 'lon' => 0.0],
            ['id' => 1, 'offset' => 'PT0M', 'lat' => 0.0, 'lon' => 0.0],
            ['id' => 2, 'offset' => 'PT3M', 'lat' => 0.01, 'lon' => 0.01],
            ['id' => 3, 'offset' => 'PT63M', 'lat' => 1.0, 'lon' => 0.0],
        ];

        $items = [];
        foreach ($points as $point) {
            $items[] = $this->makeMediaFixture(
                $point['id'],
                sprintf('point-%d.jpg', $point['id']),
                $base->add(new DateInterval($point['offset'])),
                $point['lat'],
                $point['lon'],
                configure: static function (Media $media): void {
                    $media->setTimezoneOffsetMin(0);
                },
            );
        }

        $summaries = $initialStage->process($items, $home);
        $processed = $speedStage->process($summaries, $home);

        $summary = $processed['2024-05-10'];
        $gps     = $summary['gpsMembers'];

        $gpsById = [];
        foreach ($gps as $media) {
            $gpsById[$media->getId()] = $media;
        }

        self::assertArrayHasKey(2, $gpsById);
        self::assertArrayHasKey(3, $gpsById);
        self::assertArrayHasKey(4, $gpsById);

        $firstLegSpeed  = $this->computeSpeedKmh($gpsById[2], $gpsById[3]);
        $secondLegSpeed = $this->computeSpeedKmh($gpsById[3], $gpsById[4]);
        $expectedMax    = max($firstLegSpeed, $secondLegSpeed);
        $expectedAvg    = ($firstLegSpeed + $secondLegSpeed) / 2.0;

        self::assertEqualsWithDelta($expectedMax, $summary['maxSpeedKmh'], 0.5);
        self::assertEqualsWithDelta($expectedAvg, $summary['avgSpeedKmh'], 0.5);
        self::assertTrue($summary['hasHighSpeedTransit']);
    }

    #[Test]
    public function marksHighSpeedTransitWhenTravelDistanceIsLarge(): void
    {
        $timezoneResolver = new TimezoneResolver('UTC');
        $initialStage     = new InitializationStage($timezoneResolver, new PoiClassifier(), 'UTC');
        $speedStage       = new TransportSpeedStage(5.0, 10.0, 200.0);

        $home = [
            'lat'             => 0.0,
            'lon'             => 0.0,
            'radius_km'       => 10.0,
            'country'         => 'xx',
            'timezone_offset' => 0,
        ];

        $base   = new DateTimeImmutable('2024-06-01 08:00:00', new DateTimeZone('UTC'));
        $items  = [
            $this->makeMediaFixture(10, 'travel-a.jpg', $base, 0.0, 0.0),
        ];

        $summaries = $initialStage->process($items, $home);
        $dayKey    = '2024-06-01';

        $summaries[$dayKey]['travelKm']   = 180.0;
        $summaries[$dayKey]['gpsMembers'] = [];

        $processed = $speedStage->process($summaries, $home);
        $summary   = $processed[$dayKey];

        self::assertTrue($summary['hasHighSpeedTransit']);
        self::assertSame(0.0, $summary['maxSpeedKmh']);
        self::assertSame(0.0, $summary['avgSpeedKmh']);
    }

    private function computeSpeedKmh(Media $previous, Media $current): float
    {
        $prevTakenAt = $previous->getTakenAt();
        $currTakenAt = $current->getTakenAt();

        $prevLat = $previous->getGpsLat();
        $prevLon = $previous->getGpsLon();
        $currLat = $current->getGpsLat();
        $currLon = $current->getGpsLon();

        if ($prevTakenAt === null || $currTakenAt === null || $prevLat === null || $prevLon === null || $currLat === null || $currLon === null) {
            return 0.0;
        }

        $seconds   = $currTakenAt->getTimestamp() - $prevTakenAt->getTimestamp();
        $distanceKm = MediaMath::haversineDistanceInMeters($prevLat, $prevLon, $currLat, $currLon) / 1000.0;

        return $seconds > 0 ? ($distanceKm / $seconds) * 3600.0 : 0.0;
    }
}
