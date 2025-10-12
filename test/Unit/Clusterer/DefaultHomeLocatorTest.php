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
use MagicSunday\Memories\Clusterer\DefaultHomeLocator;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DefaultHomeLocatorTest extends TestCase
{
    #[Test]
    public function returnsConfiguredHomeWhenCoordinatesAreProvided(): void
    {
        $locator = new DefaultHomeLocator(
            timezone: 'Europe/Berlin',
            defaultHomeRadiusKm: 10.0,
            homeLat: 52.5200,
            homeLon: 13.4050,
            homeRadiusKm: 8.0,
        );

        $home = $locator->determineHome([]);

        self::assertNotNull($home);
        self::assertSame(52.5200, $home['lat']);
        self::assertSame(13.4050, $home['lon']);
        self::assertSame(8.0, $home['radius_km']);
        self::assertSame('de', $home['country']);
        self::assertIsInt($home['timezone_offset']);
        self::assertSame(1, count($home['centers']));
        self::assertEqualsWithDelta(8.0, $home['centers'][0]['radius_km'], 0.0001);
        self::assertArrayHasKey('valid_from', $home['centers'][0]);
        self::assertArrayHasKey('valid_until', $home['centers'][0]);
        self::assertNull($home['centers'][0]['valid_from']);
        self::assertNull($home['centers'][0]['valid_until']);
    }

    #[Test]
    public function determinesHomeFromDaylightSamples(): void
    {
        $locator = new DefaultHomeLocator(
            timezone: 'UTC',
            defaultHomeRadiusKm: 12.0,
        );

        $homeLocation = $this->makeLocation(
            'home-daylight',
            'Hamburg, Germany',
            53.5511,
            9.9937,
            country: 'Germany',
            configure: static function (Location $location): void {
                $location->setCountryCode('DE');
                $location->setCategory('residential');
            },
        );

        $items         = [];
        $daylightStart = new DateTimeImmutable('2024-05-10 09:00:00', new DateTimeZone('UTC'));

        for ($i = 0; $i < 5; ++$i) {
            $day = $daylightStart->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[]   = $this->makeMediaFixture(
                    600 + ($i * 3) + $sample,
                    sprintf('daylight-home-%d-%d.jpg', $i, $sample),
                    $timestamp->format('Y-m-d H:i:s'),
                    $homeLocation->getLat() + (($i + $sample) * 0.0003),
                    $homeLocation->getLon() + (($i + $sample) * 0.0003),
                    $homeLocation,
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(120);
                    }
                );
            }
        }

        $home = $locator->determineHome($items);

        self::assertNotNull($home);
        self::assertEqualsWithDelta($homeLocation->getLat(), $home['lat'], 0.001);
        self::assertEqualsWithDelta($homeLocation->getLon(), $home['lon'], 0.001);
        self::assertSame('de', $home['country']);
        self::assertSame(120, $home['timezone_offset']);
        self::assertGreaterThanOrEqual(12.0, $home['radius_km']);
        self::assertNotEmpty($home['centers']);
        self::assertEqualsWithDelta($home['centers'][0]['lat'], $home['lat'], 0.0001);
        self::assertEqualsWithDelta($home['centers'][0]['lon'], $home['lon'], 0.0001);
        self::assertGreaterThanOrEqual(12.0, $home['centers'][0]['radius_km']);
        self::assertIsInt($home['centers'][0]['valid_from']);
        self::assertIsInt($home['centers'][0]['valid_until']);
    }

    #[Test]
    public function returnsMultipleCentersWhenSecondaryClusterDetected(): void
    {
        $locator = new DefaultHomeLocator(
            timezone: 'UTC',
            defaultHomeRadiusKm: 5.0,
            maxHomeCenters: 2,
        );

        $primaryLocation = $this->makeLocation('primary', 'Berlin, Deutschland', 52.5200, 13.4050, country: 'Germany');
        $secondary       = $this->makeLocation('secondary', 'München, Deutschland', 48.1371, 11.5754, country: 'Germany');

        $items = [];
        $start = new DateTimeImmutable('2024-05-01 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 6; ++$i) {
            $items[] = $this->makeMediaFixture(
                1000 + $i,
                sprintf('primary-%d.jpg', $i),
                $start->add(new DateInterval('P' . $i . 'D')),
                $primaryLocation->getLat() + ($i * 0.0002),
                $primaryLocation->getLon() + ($i * 0.0002),
                $primaryLocation,
            );
        }

        for ($i = 0; $i < 4; ++$i) {
            $items[] = $this->makeMediaFixture(
                2000 + $i,
                sprintf('secondary-%d.jpg', $i),
                $start->add(new DateInterval('P' . ($i + 6) . 'D')),
                $secondary->getLat() + ($i * 0.0003),
                $secondary->getLon() + ($i * 0.0003),
                $secondary,
            );
        }

        $home = $locator->determineHome($items);

        self::assertNotNull($home);
        self::assertCount(2, $home['centers']);
        self::assertEqualsWithDelta(52.5200, $home['centers'][0]['lat'], 0.001);
        self::assertEqualsWithDelta(48.1371, $home['centers'][1]['lat'], 0.001);
        self::assertIsInt($home['centers'][0]['valid_from']);
        self::assertIsInt($home['centers'][0]['valid_until']);
        self::assertIsInt($home['centers'][1]['valid_from']);
        self::assertIsInt($home['centers'][1]['valid_until']);
    }

    #[Test]
    public function widensRadiusForDenseLowMovementCluster(): void
    {
        $locator = new DefaultHomeLocator(
            timezone: 'UTC',
            defaultHomeRadiusKm: 4.0,
            maxHomeCenters: 1,
            fallbackRadiusScale: 2.0,
        );

        $homeLocation = $this->makeLocation('compact-home', 'Hamburg, Deutschland', 53.5511, 9.9937, country: 'Germany');
        $items        = [];
        $start        = new DateTimeImmutable('2024-06-01 09:00:00', new DateTimeZone('UTC'));

        for ($i = 0; $i < 12; ++$i) {
            $items[] = $this->makeMediaFixture(
                3000 + $i,
                sprintf('compact-%d.jpg', $i),
                $start->add(new DateInterval('PT' . ($i * 45) . 'M')),
                $homeLocation->getLat() + 0.0001,
                $homeLocation->getLon() + 0.0001,
                $homeLocation,
            );
        }

        $home = $locator->determineHome($items);

        self::assertNotNull($home);
        self::assertGreaterThanOrEqual(8.0, $home['radius_km']);
        self::assertGreaterThanOrEqual(8.0, $home['centers'][0]['radius_km']);
    }

    #[Test]
    public function computesNightPercentileRadiusAndValidityWindows(): void
    {
        $locator = new DefaultHomeLocator(
            timezone: 'Europe/Berlin',
            defaultHomeRadiusKm: 6.0,
        );

        $berlin = $this->makeLocation('berlin-home', 'Berlin, Deutschland', 52.5200, 13.4050, country: 'Germany');
        $munich = $this->makeLocation('munich-home', 'München, Deutschland', 48.1371, 11.5754, country: 'Germany');
        $tz     = new DateTimeZone('Europe/Berlin');

        $items = [];

        $berlinDays = [
            new DateTimeImmutable('2024-01-01 10:00:00', $tz),
            new DateTimeImmutable('2024-01-05 11:30:00', $tz),
            new DateTimeImmutable('2024-01-11 12:45:00', $tz),
        ];

        foreach ($berlinDays as $index => $day) {
            $items[] = $this->makeMediaFixture(
                4000 + $index,
                sprintf('berlin-day-%d.jpg', $index),
                $day,
                $berlin->getLat() + ($index * 0.0002),
                $berlin->getLon() + ($index * 0.0002),
                $berlin,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
        }

        $berlinNightStart = new DateTimeImmutable('2024-01-02 23:15:00', $tz);
        for ($i = 0; $i < 5; ++$i) {
            $night = $berlinNightStart->add(new DateInterval('P' . ($i * 2) . 'D'));
            $items[] = $this->makeMediaFixture(
                4100 + $i,
                sprintf('berlin-night-%d.jpg', $i),
                $night,
                $berlin->getLat() + 0.02 + ($i * 0.0005),
                $berlin->getLon() + 0.02 + ($i * 0.0005),
                $berlin,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
        }

        $munichDays = [
            new DateTimeImmutable('2024-06-01 11:00:00', $tz),
            new DateTimeImmutable('2024-06-04 12:30:00', $tz),
            new DateTimeImmutable('2024-06-07 13:15:00', $tz),
        ];

        foreach ($munichDays as $index => $day) {
            $items[] = $this->makeMediaFixture(
                5000 + $index,
                sprintf('munich-day-%d.jpg', $index),
                $day,
                $munich->getLat() + ($index * 0.0003),
                $munich->getLon() + ($index * 0.0003),
                $munich,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
        }

        $munichNightStart = new DateTimeImmutable('2024-06-02 22:45:00', $tz);
        for ($i = 0; $i < 20; ++$i) {
            $night   = $munichNightStart->add(new DateInterval('P' . $i . 'D'));
            $offset  = $i < 18 ? 0.18 + ($i * 0.001) : 0.28 + (($i - 18) * 0.02);
            $items[] = $this->makeMediaFixture(
                5100 + $i,
                sprintf('munich-night-%d.jpg', $i),
                $night,
                $munich->getLat() + $offset,
                $munich->getLon() + $offset,
                $munich,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
        }

        $home = $locator->determineHome($items);

        self::assertNotNull($home);
        self::assertCount(2, $home['centers']);

        $berlinCenter = null;
        $munichCenter = null;

        foreach ($home['centers'] as $center) {
            if ($berlinCenter === null && abs($center['lat'] - $berlin->getLat()) < 0.01) {
                $berlinCenter = $center;
            }

            if ($munichCenter === null && abs($center['lat'] - $munich->getLat()) < 0.01) {
                $munichCenter = $center;
            }
        }

        self::assertNotNull($berlinCenter);
        self::assertNotNull($munichCenter);

        $berlinValidFrom  = $berlinDays[0]->getTimestamp();
        $berlinLastDay    = $berlinDays[count($berlinDays) - 1];
        $berlinLastNight  = $berlinNightStart->add(new DateInterval('P8D'));
        $berlinValidUntil = max($berlinLastDay->getTimestamp(), $berlinLastNight->getTimestamp());

        $munichValidFrom  = $munichDays[0]->getTimestamp();
        $munichLastDay    = $munichDays[count($munichDays) - 1];
        $munichLastNight  = $munichNightStart->add(new DateInterval('P19D'));
        $munichValidUntil = max($munichLastDay->getTimestamp(), $munichLastNight->getTimestamp());

        self::assertEqualsWithDelta(10.0, $berlinCenter['radius_km'], 0.5);
        self::assertSame($berlinValidFrom, $berlinCenter['valid_from']);
        self::assertSame($berlinValidUntil, $berlinCenter['valid_until']);

        self::assertEqualsWithDelta(25.0, $munichCenter['radius_km'], 0.5);
        self::assertSame($munichValidFrom, $munichCenter['valid_from']);
        self::assertSame($munichValidUntil, $munichCenter['valid_until']);
    }
}
