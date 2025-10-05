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
    }
}
