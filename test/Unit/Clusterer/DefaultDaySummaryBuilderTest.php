<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\DaySummaryStage\AwayFlagStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\DensityStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\GpsMetricsStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Clusterer\DefaultDaySummaryBuilder;
use MagicSunday\Memories\Clusterer\Service\BaseLocationResolver;
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\StaypointDetector;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function array_slice;

final class DefaultDaySummaryBuilderTest extends TestCase
{
    #[Test]
    public function groupsMediaByLocalTimezoneAcrossOffsets(): void
    {
        $timezoneResolver = new TimezoneResolver('UTC');
        $builder          = $this->createBuilder(
            $timezoneResolver,
            timezone: 'UTC',
            minItemsPerDay: 1,
        );

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $newYork = $this->makeLocation(
            'nyc',
            'New York, USA',
            40.7128,
            -74.0060,
            country: 'United States',
            city: 'New York',
        );

        $tokyo = $this->makeLocation(
            'tokyo',
            'Tokyo, Japan',
            35.6762,
            139.6503,
            country: 'Japan',
            city: 'Tokyo',
        );

        $items = [];

        $items[] = $this->makeMediaFixture(
            5000,
            'nyc-evening.jpg',
            new DateTimeImmutable('2024-02-10 23:30:00', new DateTimeZone('America/New_York')),
            $newYork->getLat(),
            $newYork->getLon(),
            $newYork,
            static function (Media $media): void {
                $media->setTimezoneOffsetMin(-300);
            },
        );

        $items[] = $this->makeMediaFixture(
            5001,
            'nyc-midnight.jpg',
            new DateTimeImmutable('2024-02-11 00:15:00', new DateTimeZone('America/New_York')),
            $newYork->getLat(),
            $newYork->getLon(),
            $newYork,
            static function (Media $media): void {
                $media->setTimezoneOffsetMin(-300);
            },
        );

        $items[] = $this->makeMediaFixture(
            5002,
            'tokyo-morning.jpg',
            new DateTimeImmutable('2024-02-15 09:05:00', new DateTimeZone('Asia/Tokyo')),
            $tokyo->getLat(),
            $tokyo->getLon(),
            $tokyo,
        );

        $days = $builder->buildDaySummaries($items, $home);

        self::assertArrayHasKey('2024-02-10', $days);
        self::assertArrayHasKey('2024-02-11', $days);
        self::assertArrayHasKey('2024-02-15', $days);

        self::assertSame('-05:00', $days['2024-02-10']['localTimezoneIdentifier']);
        self::assertSame(-300, $days['2024-02-10']['localTimezoneOffset']);
        self::assertSame(1, $days['2024-02-10']['photoCount']);

        self::assertSame('-05:00', $days['2024-02-11']['localTimezoneIdentifier']);
        self::assertSame(-300, $days['2024-02-11']['localTimezoneOffset']);

        self::assertSame('Asia/Tokyo', $days['2024-02-15']['localTimezoneIdentifier']);
        self::assertSame(540, $days['2024-02-15']['localTimezoneOffset']);
    }

    #[Test]
    public function marksDaysAwayFromHomeWhenBaseLocationIsFar(): void
    {
        $timezoneResolver = new TimezoneResolver('UTC');
        $builder          = $this->createBuilder(
            $timezoneResolver,
            timezone: 'UTC',
            minItemsPerDay: 2,
        );

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $homeLocation = $this->makeLocation('home', 'Berlin', 52.5200, 13.4050, country: 'Germany');
        $awayLocation = $this->makeLocation(
            'away',
            'New York',
            40.7128,
            -74.0060,
            country: 'United States',
            configure: static function (Location $location): void {
                $location->setCategory('tourism');
                $location->setType('attraction');
            },
        );

        $items   = [];
        $homeDay = new DateTimeImmutable('2024-06-01 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $timestamp = $homeDay->add(new DateInterval('PT' . ($i * 2) . 'H'));
            $items[]   = $this->makeMediaFixture(
                100 + $i,
                sprintf('home-%d.jpg', $i),
                $timestamp,
                $homeLocation->getLat() + ($i * 0.0003),
                $homeLocation->getLon() + ($i * 0.0003),
                $homeLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
        }

        $awayDay = new DateTimeImmutable('2024-06-02 12:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $timestamp = $awayDay->add(new DateInterval('PT' . ($i * 3) . 'H'));
            $items[]   = $this->makeMediaFixture(
                200 + $i,
                sprintf('away-%d.jpg', $i),
                $timestamp,
                $awayLocation->getLat() + ($i * 0.01),
                $awayLocation->getLon() + ($i * 0.01),
                $awayLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(-240);
                }
            );
        }

        $homeOnly = $builder->buildDaySummaries(array_slice($items, 0, 3), $home);

        self::assertArrayHasKey('2024-06-01', $homeOnly);
        self::assertArrayHasKey('baseAway', $homeOnly['2024-06-01']);
        self::assertFalse($homeOnly['2024-06-01']['baseAway']);

        $days = $builder->buildDaySummaries($items, $home);

        self::assertArrayHasKey('baseAway', $days['2024-06-02']);
        self::assertTrue($days['2024-06-02']['baseAway']);
    }

    private function createBuilder(
        TimezoneResolver $timezoneResolver,
        string $timezone = 'Europe/Berlin',
        float $gpsOutlierRadiusKm = 1.0,
        int $gpsOutlierMinSamples = 3,
        int $minItemsPerDay = 3,
    ): DefaultDaySummaryBuilder {
        $stages = [
            new InitializationStage($timezoneResolver, new PoiClassifier(), $timezone),
            new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector(), $gpsOutlierRadiusKm, $gpsOutlierMinSamples, $minItemsPerDay),
            new DensityStage(),
            new AwayFlagStage($timezoneResolver, new BaseLocationResolver()),
        ];

        return new DefaultDaySummaryBuilder($stages);
    }
}
