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
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\DefaultDaySummaryBuilder;
use MagicSunday\Memories\Clusterer\DefaultHomeLocator;
use MagicSunday\Memories\Clusterer\DefaultVacationSegmentAssembler;
use MagicSunday\Memories\Clusterer\DaySummaryStage\AwayFlagStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\DensityStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\GpsMetricsStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Clusterer\Service\BaseLocationResolver;
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\StaypointDetector;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Clusterer\Service\RunDetector;
use MagicSunday\Memories\Clusterer\Service\TransportDayExtender;
use MagicSunday\Memories\Clusterer\Service\VacationScoreCalculator;
use MagicSunday\Memories\Clusterer\VacationClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\ClusterPersistenceService;
use MagicSunday\Memories\Service\Clusterer\Scoring\HolidayResolverInterface;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

final class VacationClusterStrategyTest extends TestCase
{
    #[Test]
    public function classifiesExtendedInternationalVacation(): void
    {
        $helper = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver([
                '2024-06-10',
                '2024-06-11',
                '2024-06-12',
                '2024-06-13',
                '2024-06-14',
            ]),
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
            $loc->setCity('Roma');
            $loc->setState('Lazio');
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
        $startHome = new DateTimeImmutable('2024-05-20 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 8; ++$i) {
            $day = $startHome->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('home-day-%d-%d.jpg', $i, $sample),
                    $timestamp->format('Y-m-d H:i:s'),
                    $homeLocation->getLat() + (($i + $sample) * 0.0003),
                    $homeLocation->getLon() + (($i + $sample) * 0.0003),
                    $homeLocation,
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(60);
                    }
                );
            }
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
        $vacationDayById = [];
        foreach ($tracks as $dayIndex => $coordinates) {
            $dayStart = $tripStart->add(new DateInterval('P' . $dayIndex . 'D'));
            foreach ($coordinates as $pointIndex => $data) {
                $timestamp = $dayStart->add(new DateInterval('PT' . ($pointIndex * 4) . 'H'));
                $media = $this->makeMediaFixture(
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
                $vacationDayById[$media->getId()] = $dayIndex;
                $items[] = $media;
            }

            $nightTimestamp = $dayStart->setTime(23, 30, 0);
            $nightMedia = $this->makeMediaFixture(
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
            $vacationDayById[$nightMedia->getId()] = $dayIndex;
            $items[] = $nightMedia;
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
        self::assertIsBool($params['airport_transfer']);
        self::assertArrayHasKey('spot_clusters_total', $params);
        self::assertArrayHasKey('spot_cluster_days', $params);
        self::assertArrayHasKey('spot_dwell_hours', $params);
        self::assertArrayHasKey('spot_exploration_bonus', $params);
        self::assertSame(5, $params['work_day_penalty_days']);
        self::assertSame(2.0, $params['work_day_penalty_score']);
        self::assertSame(['it'], $params['countries']);
        self::assertSame([120], $params['timezones']);
        self::assertSame('Roma', $params['place_city']);
        self::assertSame('Lazio', $params['place_region']);
        self::assertSame('Italy', $params['place_country']);
        self::assertArrayHasKey('place', $params);
        self::assertNotSame('', $params['place']);

        $centroid = $cluster->getCentroid();
        $expectedDistanceKm = MediaMath::haversineDistanceInMeters(
            52.5200,
            13.4050,
            $centroid['lat'],
            $centroid['lon'],
        ) / 1000.0;

        self::assertEqualsWithDelta($expectedDistanceKm, $params['max_distance_km'], 0.2);
        self::assertGreaterThanOrEqual($params['max_observed_distance_km'], $params['max_distance_km']);

        $clamped = $this->clampMemberList($cluster->getMembers(), 20);

        $coverage = [];
        foreach ($clamped as $memberId) {
            $day = $vacationDayById[$memberId] ?? null;
            if ($day === null) {
                continue;
            }

            $coverage[$day] = true;
        }

        for ($i = 0; $i < count($tracks); ++$i) {
            self::assertArrayHasKey($i, $coverage);
        }
    }

    #[Test]
    public function groupsMediaByLocalTimezoneAcrossOffsets(): void
    {
        $helper = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
            timezone: 'UTC',
            defaultHomeRadiusKm: 12.0,
            minAwayDistanceKm: 80.0,
            movementThresholdKm: 30.0,
            minItemsPerDay: 2,
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

        $reflection = new ReflectionClass($strategy);
        $property   = $reflection->getProperty('daySummaryBuilder');
        $property->setAccessible(true);

        $builder = $property->getValue($strategy);

        /** @var array<string, array{localTimezoneIdentifier:string,localTimezoneOffset:int|null,photoCount:int}> $days */
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
    public function classifiesRegionalWeekendAsShortTrip(): void
    {
        $helper = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(['2024-07-05']),
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
            $loc->setState('Schleswig-Holstein');
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
            $loc->setState('Schleswig-Holstein');
        });

        $id = 2000;
        $homeSeedStart = new DateTimeImmutable('2024-07-04 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 2; ++$i) {
            $day = $homeSeedStart->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('weekend-home-%d-%d.jpg', $i, $sample),
                    $timestamp->format('Y-m-d H:i:s'),
                    $homeLocation->getLat() + (($i + $sample) * 0.0004),
                    $homeLocation->getLon() + (($i + $sample) * 0.0004),
                    $homeLocation,
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(120);
                    }
                );
            }
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
        self::assertSame('vacation', $params['classification']);
        self::assertGreaterThanOrEqual(8.0, $params['score']);
        self::assertArrayHasKey('spot_clusters_total', $params);
        self::assertArrayHasKey('spot_cluster_days', $params);
        self::assertArrayHasKey('spot_dwell_hours', $params);
        self::assertArrayHasKey('spot_exploration_bonus', $params);
        self::assertGreaterThanOrEqual(2, $params['weekend_holiday_days']);
        self::assertGreaterThan(0.0, $params['weekend_holiday_bonus']);
        self::assertLessThanOrEqual(2, $params['work_day_penalty_days']);
        self::assertLessThanOrEqual(0.8, $params['work_day_penalty_score']);
        self::assertSame(['de'], $params['countries']);
        self::assertSame([120], $params['timezones']);
        self::assertArrayNotHasKey('place_city', $params);
        self::assertSame('Schleswig-Holstein', $params['place_region']);
        self::assertSame('Germany', $params['place_country']);
    }

    #[Test]
    public function awardsHolidayBonusOnWeekdays(): void
    {
        $helper = LocationHelper::createDefault();
        $holidayDates = ['2024-12-23', '2024-12-24'];
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver($holidayDates),
            timezone: 'UTC',
            defaultHomeRadiusKm: 10.0,
            minAwayDistanceKm: 60.0,
            movementThresholdKm: 500.0,
            minItemsPerDay: 3,
        );

        $items = [];
        $homeLocation = $this->makeLocation('holiday-home', 'Hamburg, Germany', 53.5511, 9.9937, country: 'Germany', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('residential');
        });

        $holidayLocation = $this->makeLocation('holiday-trip', 'Weihnachtsmarkt Leipzig', 51.3397, 12.3731, country: 'Germany', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('tourism');
            $loc->setType('attraction');
            $loc->setPois([
                [
                    'categoryKey'   => 'tourism',
                    'categoryValue' => 'attraction',
                    'tags'          => ['tourism' => 'attraction'],
                ],
            ]);
        });

        $villageLocation = $this->makeLocation('holiday-village', 'Leipzig Zentrum', 51.3400, 12.3800, country: 'Germany', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('residential');
        });

        $id = 4000;
        $homeSeedStart = new DateTimeImmutable('2024-12-20 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $day = $homeSeedStart->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('holiday-home-%d-%d.jpg', $i, $sample),
                    $timestamp->format('Y-m-d H:i:s'),
                    $homeLocation->getLat() + (($i + $sample) * 0.0004),
                    $homeLocation->getLon() + (($i + $sample) * 0.0004),
                    $homeLocation,
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(60);
                    }
                );
            }
        }

        $tripStart = new DateTimeImmutable('2024-12-23 09:00:00', new DateTimeZone('UTC'));
        for ($day = 0; $day < 2; ++$day) {
            $dayStart = $tripStart->add(new DateInterval('P' . $day . 'D'));
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('holiday-trip-%d-0.jpg', $day),
                $dayStart->format('Y-m-d H:i:s'),
                $holidayLocation->getLat(),
                $holidayLocation->getLon(),
                $holidayLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('holiday-trip-%d-1.jpg', $day),
                $dayStart->add(new DateInterval('PT6H'))->format('Y-m-d H:i:s'),
                $villageLocation->getLat(),
                $villageLocation->getLon(),
                $villageLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
            $nightShot = $dayStart->setTime(22, 45, 0);
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('holiday-night-%d.jpg', $day),
                $nightShot->format('Y-m-d H:i:s'),
                $holidayLocation->getLat(),
                $holidayLocation->getLon(),
                $holidayLocation,
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
        self::assertSame(3, $params['weekend_holiday_days']);
        self::assertSame(1.05, $params['weekend_holiday_bonus']);
        self::assertGreaterThanOrEqual(8.0, $params['score']);
        self::assertLessThanOrEqual(2, $params['work_day_penalty_days']);
        self::assertLessThanOrEqual(0.8, $params['work_day_penalty_score']);
        self::assertSame(['de'], $params['countries']);
        self::assertSame([60], $params['timezones']);
    }

    #[Test]
    public function includesAirportBufferDayAtSegmentEdges(): void
    {
        $helper = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
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
        $homeSeedStart = new DateTimeImmutable('2024-07-01 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 5; ++$i) {
            $day = $homeSeedStart->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('home-before-%d-%d.jpg', $i, $sample),
                    $timestamp,
                    $homeLocation->getLat() + (($i + $sample) * 0.0003),
                    $homeLocation->getLon() + (($i + $sample) * 0.0003),
                    $homeLocation,
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(60);
                    }
                );
            }
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

        $homeReturn = new DateTimeImmutable('2024-07-13 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $day = $homeReturn->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('home-after-%d-%d.jpg', $i, $sample),
                    $timestamp,
                    $homeLocation->getLat() + (($i + $sample) * 0.0003),
                    $homeLocation->getLon() + (($i + $sample) * 0.0003),
                    $homeLocation,
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(60);
                    }
                );
            }
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        $params = $cluster->getParams();
        self::assertSame('vacation', $params['classification']);
        self::assertSame(5, $params['away_days']);
        self::assertTrue($params['airport_transfer']);
        self::assertSame(5, $params['work_day_penalty_days']);
        self::assertSame(2.0, $params['work_day_penalty_score']);
        self::assertSame('France', $params['place_country']);
    }

    #[Test]
    public function keepsSparsePhotoDaysWithinVacationRuns(): void
    {
        $helper = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
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
        $homeSeedStart = new DateTimeImmutable('2024-06-01 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 4; ++$i) {
            $day = $homeSeedStart->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('home-seed-%d-%d.jpg', $i, $sample),
                    $timestamp,
                    $homeLocation->getLat() + (($i + $sample) * 0.0003),
                    $homeLocation->getLon() + (($i + $sample) * 0.0003),
                    $homeLocation,
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(60);
                    }
                );
            }
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
        self::assertSame(6, $params['away_days']);
        self::assertSame(5, $params['nights']);
        self::assertSame(4, $params['work_day_penalty_days']);
        self::assertSame(1.6, $params['work_day_penalty_score']);

        foreach ($sparsePhotoIds as $photoId) {
            self::assertContains($photoId, $cluster->getMembers());
        }
    }

    #[Test]
    public function recognisesMultiSpotExplorationWithinTrip(): void
    {
        $helper = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
            timezone: 'UTC',
            defaultHomeRadiusKm: 12.0,
            minAwayDistanceKm: 90.0,
            movementThresholdKm: 25.0,
            minItemsPerDay: 3,
        );

        $items = [];
        $homeLocation = $this->makeLocation('home-berlin', 'Berlin, Germany', 52.5200, 13.4050, country: 'Germany', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('residential');
        });

        $sagradaLocation = $this->makeLocation('spot-sagrada', 'Sagrada Família', 41.4036, 2.1744, country: 'Spain', configure: static function (Location $loc): void {
            $loc->setCountryCode('ES');
            $loc->setCategory('tourism');
            $loc->setType('attraction');
            $loc->setPois([
                [
                    'categoryKey'   => 'tourism',
                    'categoryValue' => 'church',
                    'tags'          => ['tourism' => 'attraction'],
                ],
            ]);
        });

        $parkLocation = $this->makeLocation('spot-park', 'Park Güell', 41.4145, 2.1527, country: 'Spain', configure: static function (Location $loc): void {
            $loc->setCountryCode('ES');
            $loc->setCategory('tourism');
            $loc->setType('attraction');
            $loc->setPois([
                [
                    'categoryKey'   => 'tourism',
                    'categoryValue' => 'park',
                    'tags'          => ['tourism' => 'park'],
                ],
            ]);
        });

        $gothicLocation = $this->makeLocation('spot-gothic', 'Barri Gòtic', 41.3830, 2.1760, country: 'Spain', configure: static function (Location $loc): void {
            $loc->setCountryCode('ES');
            $loc->setCategory('tourism');
            $loc->setType('attraction');
            $loc->setPois([
                [
                    'categoryKey'   => 'tourism',
                    'categoryValue' => 'sight',
                    'tags'          => ['tourism' => 'attraction'],
                ],
            ]);
        });

        $montjuicLocation = $this->makeLocation('spot-montjuic', 'Montjuïc', 41.3630, 2.1650, country: 'Spain', configure: static function (Location $loc): void {
            $loc->setCountryCode('ES');
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

        $id = 9000;
        $homeSeedStart = new DateTimeImmutable('2024-04-10 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $day = $homeSeedStart->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('home-pre-%d-%d.jpg', $i, $sample),
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

        $dayClusters = [
            [
                ['location' => $sagradaLocation, 'points' => [
                    ['lat' => 41.4036, 'lon' => 2.1744],
                    ['lat' => 41.4038, 'lon' => 2.1742],
                    ['lat' => 41.4034, 'lon' => 2.1746],
                ]],
                ['location' => $parkLocation, 'points' => [
                    ['lat' => 41.4145, 'lon' => 2.1527],
                    ['lat' => 41.4147, 'lon' => 2.1529],
                    ['lat' => 41.4143, 'lon' => 2.1525],
                ]],
            ],
            [
                ['location' => $gothicLocation, 'points' => [
                    ['lat' => 41.3830, 'lon' => 2.1760],
                    ['lat' => 41.3832, 'lon' => 2.1758],
                    ['lat' => 41.3828, 'lon' => 2.1762],
                ]],
                ['location' => $montjuicLocation, 'points' => [
                    ['lat' => 41.3630, 'lon' => 2.1650],
                    ['lat' => 41.3632, 'lon' => 2.1652],
                    ['lat' => 41.3628, 'lon' => 2.1648],
                ]],
            ],
        ];

        $tripStart = new DateTimeImmutable('2024-04-15 09:00:00', new DateTimeZone('UTC'));
        foreach ($dayClusters as $dayIndex => $clustersForDay) {
            $dayStart = $tripStart->add(new DateInterval('P' . $dayIndex . 'D'));
            foreach ($clustersForDay as $clusterIndex => $clusterData) {
                $baseHour = $clusterIndex === 0 ? 9 : 14;
                foreach ($clusterData['points'] as $pointIndex => $coordinates) {
                    $timestamp = $dayStart->setTime($baseHour, 0, 0)->add(new DateInterval('PT' . ($pointIndex * 30) . 'M'));
                    $items[] = $this->makeMediaFixture(
                        ++$id,
                        sprintf('trip-%d-%d-%d.jpg', $dayIndex, $clusterIndex, $pointIndex),
                        $timestamp->format('Y-m-d H:i:s'),
                        (float) $coordinates['lat'],
                        (float) $coordinates['lon'],
                        $clusterData['location'],
                        static function (Media $media): void {
                            $media->setTimezoneOffsetMin(120);
                        }
                    );
                }
            }

            $nightLocation = $clustersForDay[1]['location'];
            $nightPoint    = $clustersForDay[1]['points'][1];
            $nightTimestamp = $dayStart->setTime(22, 30, 0);
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('trip-night-%d.jpg', $dayIndex),
                $nightTimestamp->format('Y-m-d H:i:s'),
                (float) $nightPoint['lat'],
                (float) $nightPoint['lon'],
                $nightLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
        }

        $returnNight = new DateTimeImmutable('2024-04-18 22:15:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $timestamp = $returnNight->add(new DateInterval('P' . $i . 'D'));
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('home-post-%d.jpg', $i),
                $timestamp->format('Y-m-d H:i:s'),
                $homeLocation->getLat(),
                $homeLocation->getLon(),
                $homeLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
        }

        $clusters = $strategy->cluster($items);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        $params = $cluster->getParams();
        self::assertSame('vacation', $params['classification']);
        self::assertGreaterThanOrEqual(8.0, $params['score']);
        self::assertSame(4, $params['spot_clusters_total']);
        self::assertSame(2, $params['spot_cluster_days']);
        self::assertGreaterThan(0.0, $params['spot_exploration_bonus']);
        self::assertGreaterThan(0.0, $params['spot_dwell_hours']);
        self::assertSame(4, $params['work_day_penalty_days']);
        self::assertSame(1.6, $params['work_day_penalty_score']);
    }

    #[Test]
    public function keepsDstTransitionWithinSingleVacationRun(): void
    {
        $helper = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
            timezone: 'Europe/Berlin',
            defaultHomeRadiusKm: 12.0,
            minAwayDistanceKm: 80.0,
            movementThresholdKm: 25.0,
            minItemsPerDay: 2,
        );

        $homeLocation = $this->makeLocation('home-berlin', 'Berlin, Germany', 52.5200, 13.4050, country: 'Germany', configure: static function (Location $loc): void {
            $loc->setCountryCode('DE');
            $loc->setCategory('residential');
        });

        $tripLocation = $this->makeLocation('trip-lisbon', 'Lisboa, Portugal', 38.7223, -9.1393, country: 'Portugal', configure: static function (Location $loc): void {
            $loc->setCountryCode('PT');
            $loc->setCategory('tourism');
            $loc->setType('attraction');
        });

        $items = [];
        $id = 6000;

        $homeBaseline = new DateTimeImmutable('2024-03-24 09:00:00', new DateTimeZone('Europe/Berlin'));
        for ($i = 0; $i < 3; ++$i) {
            $day = $homeBaseline->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('home-before-%d-%d.jpg', $i, $sample),
                    $timestamp,
                    $homeLocation->getLat() + (($i + $sample) * 0.0003),
                    $homeLocation->getLon() + (($i + $sample) * 0.0003),
                    $homeLocation,
                    static function (Media $media): void {
                        $media->setTimezoneOffsetMin(60);
                    }
                );
            }
        }

        $tripStart = new DateTimeImmutable('2024-03-30 09:00:00', new DateTimeZone('Europe/Berlin'));
        $lastNightId = null;
        for ($day = 0; $day < 4; ++$day) {
            $dayStart = $tripStart->add(new DateInterval('P' . $day . 'D'));

            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('trip-day-%d.jpg', $day),
                $dayStart->setTime(11, 0, 0),
                $tripLocation->getLat() + ($day * 0.01),
                $tripLocation->getLon() + ($day * 0.01),
                $tripLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(0);
                }
            );

            $nightTimestamp = $dayStart->setTime(22, 30, 0);
            $nightId = ++$id;
            $items[] = $this->makeMediaFixture(
                $nightId,
                sprintf('trip-night-%d.jpg', $day),
                $nightTimestamp,
                $tripLocation->getLat(),
                $tripLocation->getLon(),
                $tripLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(0);
                }
            );

            if ($day === 3) {
                $lastNightId = $nightId;
            }
        }

        $returnBaseline = new DateTimeImmutable('2024-04-04 09:00:00', new DateTimeZone('Europe/Berlin'));
        for ($i = 0; $i < 2; ++$i) {
            $day = $returnBaseline->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[] = $this->makeMediaFixture(
                    ++$id,
                    sprintf('home-after-%d-%d.jpg', $i, $sample),
                    $timestamp,
                    $homeLocation->getLat() + (($i + $sample) * 0.0003),
                    $homeLocation->getLon() + (($i + $sample) * 0.0003),
                    $homeLocation,
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
        self::assertSame(7, $params['away_days']);
        self::assertSame(6, $params['nights']);
        self::assertSame(5, $params['work_day_penalty_days']);
        self::assertSame(2.0, $params['work_day_penalty_score']);
        self::assertSame('Portugal', $params['place_country']);
        self::assertNotNull($lastNightId);
        self::assertContains($lastNightId, $cluster->getMembers());
    }

    #[Test]
    public function ignoresExtremeGpsOutliersWhenScoringAwayDays(): void
    {
        $helper = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
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
        $helper = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
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

    private function makeStrategy(
        LocationHelper $locationHelper,
        ?HolidayResolverInterface $holidayResolver = null,
        string $timezone = 'Europe/Berlin',
        float $defaultHomeRadiusKm = 15.0,
        float $minAwayDistanceKm = 120.0,
        float $movementThresholdKm = 35.0,
        int $minItemsPerDay = 3,
        float $gpsOutlierRadiusKm = 1.0,
        int $gpsOutlierMinSamples = 3,
        ?float $homeLat = null,
        ?float $homeLon = null,
        ?float $homeRadiusKm = null,
    ): VacationClusterStrategy {
        $homeLocator = new DefaultHomeLocator(
            timezone: $timezone,
            defaultHomeRadiusKm: $defaultHomeRadiusKm,
            homeLat: $homeLat,
            homeLon: $homeLon,
            homeRadiusKm: $homeRadiusKm,
        );

        $timezoneResolver = new TimezoneResolver($timezone);
        $dayBuilder = new DefaultDaySummaryBuilder([
            new InitializationStage($timezoneResolver, new PoiClassifier(), $timezone),
            new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector(), $gpsOutlierRadiusKm, $gpsOutlierMinSamples, $minItemsPerDay),
            new DensityStage(),
            new AwayFlagStage($timezoneResolver, new BaseLocationResolver()),
        ]);

        $transportExtender = new TransportDayExtender();
        $runDetector = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: $minAwayDistanceKm,
            minItemsPerDay: $minItemsPerDay,
        );

        $scoreCalculator = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            holidayResolver: $holidayResolver ?? $this->createHolidayResolver(),
            timezone: $timezone,
            movementThresholdKm: $movementThresholdKm,
        );

        $segmentAssembler = new DefaultVacationSegmentAssembler($runDetector, $scoreCalculator);

        return new VacationClusterStrategy($homeLocator, $dayBuilder, $segmentAssembler);
    }

    /**
     * @param list<int> $memberIds
     *
     * @return list<int>
     */
    private function clampMemberList(array $memberIds, int $limit): array
    {
        $service = new ClusterPersistenceService(
            $this->createStub(EntityManagerInterface::class),
            250,
            $limit,
        );

        $reflection = new ReflectionClass(ClusterPersistenceService::class);
        $method     = $reflection->getMethod('clampMembers');
        $method->setAccessible(true);

        /** @var list<int> $result */
        $result = $method->invoke($service, $memberIds);

        return $result;
    }

    /**
     * @param list<string> $holidayDates
     */
    private function createHolidayResolver(array $holidayDates = []): HolidayResolverInterface
    {
        $resolver = $this->createMock(HolidayResolverInterface::class);
        $resolver
            ->method('isHoliday')
            ->willReturnCallback(static function (DateTimeImmutable $day) use ($holidayDates): bool {
                return in_array($day->format('Y-m-d'), $holidayDates, true);
            });

        return $resolver;
    }
}
