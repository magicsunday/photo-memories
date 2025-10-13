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
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\StaypointDetector;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class GpsMetricsStageTest extends TestCase
{
    #[Test]
    public function computesGpsStatisticsAndStaypoints(): void
    {
        $timezoneResolver = new TimezoneResolver('Europe/Berlin');
        $initialStage     = new InitializationStage($timezoneResolver, new PoiClassifier(), 'Europe/Berlin');
        $gpsStage         = new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector(), 1.0, 3, 2);

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $items = [];
        $start = new DateTimeImmutable('2024-04-01 08:00:00', new DateTimeZone('Europe/Berlin'));
        for ($i = 0; $i < 3; ++$i) {
            $timestamp = $start->add(new DateInterval('PT' . ($i * 2) . 'H'));
            $items[]   = $this->makeMediaFixture(
                100 + $i,
                sprintf('day-%d.jpg', $i),
                $timestamp,
                52.5200 + ($i * 0.01),
                13.4050 + ($i * 0.01),
                configure: static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                },
            );
        }

        $initial = $initialStage->process($items, $home);
        $result  = $gpsStage->process($initial, $home);

        $summary = $result['2024-04-01'];
        self::assertGreaterThan(0.0, $summary['travelKm']);
        self::assertGreaterThan(0.0, $summary['avgDistanceKm']);
        self::assertSame(count($summary['spotClusters']), $summary['spotCount']);
        self::assertSame(count($summary['spotNoise']), $summary['spotNoiseSamples']);
        self::assertArrayHasKey('spotDensity', $summary);
        self::assertGreaterThanOrEqual(0.0, $summary['spotDensity']);
        self::assertTrue($summary['sufficientSamples']);
        self::assertIsArray($summary['staypoints']);
    }

    #[Test]
    public function marksDayAsInsufficientWhenBelowDefaultThreshold(): void
    {
        $timezoneResolver = new TimezoneResolver('Europe/Berlin');
        $initialStage     = new InitializationStage($timezoneResolver, new PoiClassifier(), 'Europe/Berlin');
        $gpsStage         = new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector());

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $items = [];
        $start = new DateTimeImmutable('2024-05-01 07:00:00', new DateTimeZone('Europe/Berlin'));
        for ($i = 0; $i < 3; ++$i) {
            $items[] = $this->makeMediaFixture(
                200 + $i,
                sprintf('insufficient-%d.jpg', $i),
                $start->add(new DateInterval('PT' . ($i * 2) . 'H')),
                52.5 + ($i * 0.005),
                13.4 + ($i * 0.005),
            );
        }

        $initial = $initialStage->process($items, $home);
        $result  = $gpsStage->process($initial, $home);

        self::assertFalse($result['2024-05-01']['sufficientSamples']);
    }
}
