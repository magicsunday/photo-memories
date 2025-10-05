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
}
