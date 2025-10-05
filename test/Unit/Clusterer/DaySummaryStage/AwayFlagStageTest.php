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
use MagicSunday\Memories\Clusterer\DaySummaryStage\AwayFlagStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\DensityStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\GpsMetricsStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Clusterer\Service\BaseLocationResolver;
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\StaypointDetector;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AwayFlagStageTest extends TestCase
{
    #[Test]
    public function marksAwayDaysBasedOnBaseLocationAndDistance(): void
    {
        $timezoneResolver = new TimezoneResolver('Europe/Berlin');
        $initialStage     = new InitializationStage($timezoneResolver, new PoiClassifier(), 'Europe/Berlin');
        $gpsStage         = new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector(), 1.0, 3, 3);
        $densityStage     = new DensityStage();
        $awayStage        = new AwayFlagStage($timezoneResolver, new BaseLocationResolver());

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $berlin = $this->makeLocation('berlin', 'Berlin, Germany', 52.5200, 13.4050, country: 'Germany');
        $nyc    = $this->makeLocation(
            'nyc',
            'New York, USA',
            40.7128,
            -74.0060,
            country: 'United States',
            configure: static function (Location $location): void {
                $location->setCategory('tourism');
                $location->setType('attraction');
            },
        );

        $items     = [];
        $homeStart = new DateTimeImmutable('2024-06-01 08:00:00', new DateTimeZone('Europe/Berlin'));
        for ($i = 0; $i < 3; ++$i) {
            $timestamp = $homeStart->add(new DateInterval('PT' . ($i * 2) . 'H'));
            $items[]   = $this->makeMediaFixture(
                10 + $i,
                sprintf('home-%d.jpg', $i),
                $timestamp,
                $berlin->getLat() + ($i * 0.0002),
                $berlin->getLon() + ($i * 0.0002),
                $berlin,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                },
            );
        }

        $awayStart = new DateTimeImmutable('2024-06-02 12:00:00', new DateTimeZone('America/New_York'));
        for ($i = 0; $i < 3; ++$i) {
            $timestamp = $awayStart->add(new DateInterval('PT' . ($i * 3) . 'H'));
            $items[]   = $this->makeMediaFixture(
                20 + $i,
                sprintf('away-%d.jpg', $i),
                $timestamp,
                $nyc->getLat() + ($i * 0.01),
                $nyc->getLon() + ($i * 0.01),
                $nyc,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(-240);
                },
            );
        }

        $initial = $initialStage->process($items, $home);
        $gps     = $gpsStage->process($initial, $home);
        $dense   = $densityStage->process($gps, $home);
        $result  = $awayStage->process($dense, $home);

        self::assertFalse($result['2024-06-01']['awayByDistance']);
        self::assertTrue($result['2024-06-02']['baseAway']);
    }
}
