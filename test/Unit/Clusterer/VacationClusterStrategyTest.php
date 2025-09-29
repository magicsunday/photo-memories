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
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\VacationClusterStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class VacationClusterStrategyTest extends TestCase
{
    #[Test]
    public function classifiesExtendedInternationalVacation(): void
    {
        $helper = new LocationHelper();
        $strategy = new VacationClusterStrategy(
            locationHelper: $helper,
            timezone: 'UTC',
            defaultHomeRadiusKm: 12.0,
            minAwayDistanceKm: 80.0,
            movementThresholdKm: 30.0,
            minItemsPerDay: 3,
        );

        $items = [];
        $homeLocation = $this->makeLocation('home-berlin', 'Berlin, Germany', 52.5200, 13.4050, country: 'Germany', suburb: 'Mitte', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('residential');
        });

        $romeLocation = $this->makeLocation('vac-rome', 'Roma, Italien', 41.9028, 12.4964, country: 'Italy', configure: static function (Location $loc): void {
            $loc->setCountryCode('IT');
            $loc->setCategory('tourism');
            $loc->setType('attraction');
            $loc->setPois([
                [
                    'categoryKey'   => 'tourism',
                    'categoryValue' => 'museum',
                    'tags'          => ['tourism' => 'museum'],
                ],
            ]);
        });

        $airportLocation = $this->makeLocation('vac-fco', 'Aeroporto di Roma', 41.8003, 12.2389, country: 'Italy', configure: static function (Location $loc): void {
            $loc->setCountryCode('IT');
            $loc->setCategory('aeroway');
            $loc->setType('airport');
            $loc->setPois([
                [
                    'categoryKey'   => 'aeroway',
                    'categoryValue' => 'aerodrome',
                    'tags'          => ['aeroway' => 'aerodrome'],
                ],
            ]);
        });

        $id = 1000;
        $startHome = new DateTimeImmutable('2024-05-20 22:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 8; ++$i) {
            $timestamp = $startHome->add(new DateInterval('P' . $i . 'D'));
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('home-night-%d.jpg', $id),
                $timestamp->format('Y-m-d H:i:s'),
                $homeLocation->getLat(),
                $homeLocation->getLon(),
                $homeLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
        }

        $tracks = [
            [
                ['lat' => 41.8003, 'lon' => 12.2389, 'location' => $airportLocation],
                ['lat' => 41.9000, 'lon' => 12.4800, 'location' => $romeLocation],
                ['lat' => 41.9050, 'lon' => 12.5000, 'location' => $romeLocation],
                ['lat' => 41.9150, 'lon' => 12.5200, 'location' => $romeLocation],
            ],
            [
                ['lat' => 41.9500, 'lon' => 12.5500, 'location' => $romeLocation],
                ['lat' => 41.9700, 'lon' => 12.6000, 'location' => $romeLocation],
                ['lat' => 41.9800, 'lon' => 12.6300, 'location' => $romeLocation],
                ['lat' => 41.9900, 'lon' => 12.5800, 'location' => $romeLocation],
            ],
            [
                ['lat' => 42.0000, 'lon' => 12.5000, 'location' => $romeLocation],
                ['lat' => 42.0500, 'lon' => 12.5500, 'location' => $romeLocation],
                ['lat' => 42.0200, 'lon' => 12.5800, 'location' => $romeLocation],
                ['lat' => 42.0300, 'lon' => 12.6200, 'location' => $romeLocation],
            ],
            [
                ['lat' => 41.9700, 'lon' => 12.5100, 'location' => $romeLocation],
                ['lat' => 41.9600, 'lon' => 12.4500, 'location' => $romeLocation],
                ['lat' => 41.9400, 'lon' => 12.4300, 'location' => $romeLocation],
                ['lat' => 41.9200, 'lon' => 12.4200, 'location' => $romeLocation],
            ],
            [
                ['lat' => 41.8800, 'lon' => 12.4000, 'location' => $romeLocation],
                ['lat' => 41.8600, 'lon' => 12.3900, 'location' => $romeLocation],
                ['lat' => 41.8400, 'lon' => 12.3600, 'location' => $romeLocation],
                ['lat' => 41.8200, 'lon' => 12.3300, 'location' => $romeLocation],
            ],
        ];

        $tripStart = new DateTimeImmutable('2024-06-10 07:00:00', new DateTimeZone('UTC'));
        foreach ($tracks as $dayIndex => $coordinates) {
            $dayStart = $tripStart->add(new DateInterval('P' . $dayIndex . 'D'));
            foreach ($coordinates as $pointIndex => $data) {
                $timestamp = $dayStart->add(new DateInterval('PT' . ($pointIndex * 4) . 'H'));
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('vacation-%d-%d.jpg', $dayIndex, $pointIndex),
                    $timestamp->format('Y-m-d H:i:s'),
                    (float) $data['lat'],
                    (float) $data['lon'],
                    $data['location'],
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(120);
                    }
                );
            }

            $nightTimestamp = $dayStart->setTime(23, 30, 0);
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('vacation-night-%d.jpg', $dayIndex),
                $nightTimestamp->format('Y-m-d H:i:s'),
                $coordinates[3]['lat'],
                $coordinates[3]['lon'],
                $romeLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('vacation', $cluster->getAlgorithm());

        $params = $cluster->getParams();
        self::assertSame('vacation', $params['classification']);
        self::assertGreaterThanOrEqual(8.0, $params['score']);
        self::assertTrue($params['country_change']);
        self::assertTrue($params['timezone_change']);
        self::assertTrue($params['airport_transfer']);
    }

    #[Test]
    public function classifiesRegionalWeekendAsShortTrip(): void
    {
        $helper = new LocationHelper();
        $strategy = new VacationClusterStrategy(
            locationHelper: $helper,
            timezone: 'UTC',
            defaultHomeRadiusKm: 10.0,
            minAwayDistanceKm: 60.0,
            movementThresholdKm: 500.0,
            minItemsPerDay: 3,
        );

        $items = [];
        $homeLocation = $this->makeLocation('home-hamburg', 'Hamburg, Germany', 53.5511, 9.9937, country: 'Germany', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('residential');
        });

        $getawayLocation = $this->makeLocation('weekend-baltic', 'Ostsee', 53.7000, 10.1000, country: 'Germany', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('tourism');
            $loc->setType('beach');
            $loc->setPois([
                [
                    'categoryKey'   => 'tourism',
                    'categoryValue' => 'beach',
                    'tags'          => ['tourism' => 'beach'],
                ],
            ]);
        });
        $villageLocation = $this->makeLocation('weekend-village', 'Norddorf', 53.7200, 10.0600, country: 'Germany', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('residential');
        });

        $id = 2000;
        $homeNight = new DateTimeImmutable('2024-07-04 22:30:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $timestamp = $homeNight->add(new DateInterval('P' . $i . 'D'));
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('weekend-home-%d.jpg', $id),
                $timestamp->format('Y-m-d H:i:s'),
                $homeLocation->getLat(),
                $homeLocation->getLon(),
                $homeLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
        }

        $weekendStart = new DateTimeImmutable('2024-07-06 09:00:00', new DateTimeZone('UTC'));
        for ($day = 0; $day < 2; ++$day) {
            $dayStart = $weekendStart->add(new DateInterval('P' . $day . 'D'));
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('weekend-trip-%d-0.jpg', $day),
                $dayStart->format('Y-m-d H:i:s'),
                53.7050,
                10.1050,
                $getawayLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('weekend-trip-%d-1.jpg', $day),
                $dayStart->add(new DateInterval('PT6H'))->format('Y-m-d H:i:s'),
                53.7200,
                10.0600,
                $villageLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
            $nightShot = $dayStart->setTime(22, 45, 0);
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('weekend-night-%d.jpg', $day),
                $nightShot->format('Y-m-d H:i:s'),
                53.6950,
                10.0950,
                $getawayLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];
        $params = $cluster->getParams();

        self::assertSame('vacation', $cluster->getAlgorithm());
        self::assertSame('short_trip', $params['classification']);
        self::assertGreaterThanOrEqual(6.0, $params['score']);
        self::assertLessThan(8.0, $params['score']);
    }

    #[Test]
    public function includesAirportBufferDayAtSegmentEdges(): void
    {
        $helper = new LocationHelper();
        $strategy = new VacationClusterStrategy(
            locationHelper: $helper,
            timezone: 'UTC',
            defaultHomeRadiusKm: 15.0,
            minAwayDistanceKm: 80.0,
            movementThresholdKm: 25.0,
            minItemsPerDay: 3,
        );

        $items = [];
        $homeLocation = $this->makeLocation('home-berlin', 'Berlin, Germany', 52.5200, 13.4050, country: 'Germany', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('residential');
        });

        $tripLocation = $this->makeLocation('trip-paris', 'Paris, France', 48.8566, 2.3522, country: 'France', configure: static function (Location $loc): void {
            $loc->setCountryCode('FR');
            $loc->setCategory('tourism');
            $loc->setType('attraction');
        });

        $airportLocation = $this->makeLocation('airport-ber', 'Flughafen Berlin', 52.3667, 13.5033, country: 'Germany', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('aeroway');
            $loc->setType('airport');
            $loc->setPois([
                [
                    'categoryKey'   => 'aeroway',
                    'categoryValue' => 'aerodrome',
                    'tags'          => ['aeroway' => 'aerodrome'],
                ],
            ]);
        });

        $id = 5000;
        $homeNight = new DateTimeImmutable('2024-07-01 22:30:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 5; ++$i) {
            $timestamp = $homeNight->add(new DateInterval('P' . $i . 'D'));
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('home-before-%d.jpg', $i),
                $timestamp,
                $homeLocation->getLat(),
                $homeLocation->getLon(),
                $homeLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
        }

        $tripStart = new DateTimeImmutable('2024-07-10 09:00:00', new DateTimeZone('UTC'));
        for ($day = 0; $day < 2; ++$day) {
            $dayStart = $tripStart->add(new DateInterval('P' . $day . 'D'));
            for ($photo = 0; $photo < 3; ++$photo) {
                $timestamp = $dayStart->add(new DateInterval('PT' . ($photo * 3) . 'H'));
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('trip-%d-%d.jpg', $day, $photo),
                    $timestamp,
                    $tripLocation->getLat() + ($photo * 0.01),
                    $tripLocation->getLon() + ($photo * 0.01),
                    $tripLocation,
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(120);
                    }
                );
            }

            $nightTimestamp = $dayStart->setTime(23, 15, 0);
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('trip-night-%d.jpg', $day),
                $nightTimestamp,
                $tripLocation->getLat(),
                $tripLocation->getLon(),
                $tripLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
        }

        $returnDay = new DateTimeImmutable('2024-07-12 08:00:00', new DateTimeZone('UTC'));
        for ($photo = 0; $photo < 2; ++$photo) {
            $timestamp = $returnDay->add(new DateInterval('PT' . ($photo * 2) . 'H'));
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('airport-%d.jpg', $photo),
                $timestamp,
                $airportLocation->getLat(),
                $airportLocation->getLon(),
                $airportLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
        }

        $homeReturn = new DateTimeImmutable('2024-07-13 22:30:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $timestamp = $homeReturn->add(new DateInterval('P' . $i . 'D'));
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('home-after-%d.jpg', $i),
                $timestamp,
                $homeLocation->getLat(),
                $homeLocation->getLon(),
                $homeLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        $params = $cluster->getParams();
        self::assertSame('vacation', $params['classification']);
        self::assertSame(3, $params['away_days']);
        self::assertTrue($params['airport_transfer']);
    }

    #[Test]
    public function keepsSparsePhotoDaysWithinVacationRuns(): void
    {
        $helper = new LocationHelper();
        $strategy = new VacationClusterStrategy(
            locationHelper: $helper,
            timezone: 'UTC',
            defaultHomeRadiusKm: 12.0,
            minAwayDistanceKm: 90.0,
            movementThresholdKm: 25.0,
            minItemsPerDay: 3,
        );

        $items = [];
        $homeLocation = $this->makeLocation('home-munich', 'Munich, Germany', 48.137, 11.575, country: 'Germany', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('residential');
        });

        $mountainLocation = $this->makeLocation('trip-alps', 'Dolomiti, Italy', 46.4100, 11.8430, country: 'Italy', configure: static function (Location $loc): void {
            $loc->setCountryCode('IT');
            $loc->setCategory('tourism');
            $loc->setType('viewpoint');
            $loc->setPois([
                [
                    'categoryKey'   => 'tourism',
                    'categoryValue' => 'viewpoint',
                    'tags'          => ['tourism' => 'viewpoint'],
                ],
            ]);
        });

        $id = 6000;
        $homeNight = new DateTimeImmutable('2024-06-01 22:30:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 4; ++$i) {
            $timestamp = $homeNight->add(new DateInterval('P' . $i . 'D'));
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('home-seed-%d.jpg', $i),
                $timestamp,
                $homeLocation->getLat(),
                $homeLocation->getLon(),
                $homeLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
        }

        $tripStart = new DateTimeImmutable('2024-06-10 08:00:00', new DateTimeZone('UTC'));
        $sparsePhotoIds = [];
        for ($day = 0; $day < 3; ++$day) {
            $dayStart = $tripStart->add(new DateInterval('P' . $day . 'D'));
            $photoCount = $day === 1 ? 2 : 4;

            for ($photo = 0; $photo < $photoCount; ++$photo) {
                $timestamp = $dayStart->add(new DateInterval('PT' . ($photo * 4) . 'H'));
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('trip-%d-%d.jpg', $day, $photo),
                    $timestamp,
                    $mountainLocation->getLat() + ($photo * 0.01),
                    $mountainLocation->getLon() + ($photo * 0.01),
                    $mountainLocation,
                    static function (Media $media) use ($day): void {
                        $media->setTimezoneOffsetMin($day === 0 ? 120 : 90);
                    }
                );

                if ($day === 1) {
                    $sparsePhotoIds[] = $id;
                }
            }

            if ($day !== 1) {
                $nightTimestamp = $dayStart->setTime(22, 45, 0);
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('trip-night-%d.jpg', $day),
                    $nightTimestamp,
                    $mountainLocation->getLat(),
                    $mountainLocation->getLon(),
                    $mountainLocation,
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(120);
                    }
                );
            }
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        $params = $cluster->getParams();
        self::assertSame(3, $params['away_days']);
        self::assertSame(2, $params['nights']);

        foreach ($sparsePhotoIds as $photoId) {
            self::assertContains($photoId, $cluster->getMembers());
        }
    }

    #[Test]
    public function ignoresExtremeGpsOutliersWhenScoringAwayDays(): void
    {
        $helper = new LocationHelper();
        $strategy = new VacationClusterStrategy(
            locationHelper: $helper,
            timezone: 'UTC',
            defaultHomeRadiusKm: 12.0,
            minAwayDistanceKm: 80.0,
            movementThresholdKm: 25.0,
            minItemsPerDay: 3,
            gpsOutlierRadiusKm: 2.0,
            gpsOutlierMinSamples: 3,
        );

        $homeLocation = $this->makeLocation(
            'home-berlin',
            'Berlin, Germany',
            52.5200,
            13.4050,
            country: 'Germany',
            suburb: 'Mitte',
            configure: static function (Location $location): void {
                $location->setCountryCode('DE');
                $location->setCategory('residential');
            }
        );

        $outlierLocation = $this->makeLocation(
            'outlier-sydney',
            'Sydney, Australia',
            -33.8688,
            151.2093,
            country: 'Australia',
            suburb: 'Sydney',
            configure: static function (Location $location): void {
                $location->setCountryCode('AU');
                $location->setCategory('tourism');
            }
        );

        $items = [];
        $id = 9000;
        $start = new DateTimeImmutable('2024-08-01 09:00:00', new DateTimeZone('UTC'));

        for ($day = 0; $day < 3; ++$day) {
            $dayStart = $start->add(new DateInterval('P' . $day . 'D'));

            for ($photo = 0; $photo < 3; ++$photo) {
                $timestamp = $dayStart->add(new DateInterval('PT' . ($photo * 3) . 'H'));
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('home-%d-%d.jpg', $day, $photo),
                    $timestamp,
                    $homeLocation->getLat() + ($photo * 0.0005),
                    $homeLocation->getLon() + ($photo * 0.0005),
                    $homeLocation,
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(60);
                    }
                );
            }

            $outlierTimestamp = $dayStart->setTime(12, 0, 0);
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('outlier-%d.jpg', $day),
                $outlierTimestamp,
                $outlierLocation->getLat(),
                $outlierLocation->getLon(),
                $outlierLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(600);
                }
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertSame([], $clusters);
    }

    #[Test]
    public function returnsEmptyWhenHomeCannotBeDerived(): void
    {
        $helper = new LocationHelper();
        $strategy = new VacationClusterStrategy(
            locationHelper: $helper,
            timezone: 'UTC',
            defaultHomeRadiusKm: 10.0,
            minAwayDistanceKm: 60.0,
            movementThresholdKm: 30.0,
            minItemsPerDay: 3,
        );

        $items = [];
        $location = $this->makeLocation('no-home', 'Anywhere', 48.2082, 16.3738, country: 'Austria', configure: static function (Location $loc): void {
            $loc->setCountryCode('AT');
            $loc->setCategory('tourism');
        });

        $base = new DateTimeImmutable('2024-08-10 12:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 4; ++$i) {
            $timestamp = $base->add(new DateInterval('P' . $i . 'D'));
            $items[] = $this->makeMediaFixture(
                3000 + $i,
                sprintf('no-home-%d.jpg', $i),
                $timestamp->format('Y-m-d H:i:s'),
                48.2082,
                16.3738,
                $location
            );
        }

        self::assertSame([], $strategy->cluster($items));
    }
}
