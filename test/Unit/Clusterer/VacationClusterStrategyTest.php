<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use MagicSunday\Memories\Clusterer\Context;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Contract\DaySummaryBuilderInterface;
use MagicSunday\Memories\Clusterer\Contract\HomeLocatorInterface;
use MagicSunday\Memories\Clusterer\Contract\VacationSegmentAssemblerInterface;
use MagicSunday\Memories\Clusterer\Support\StaypointIndex;
use MagicSunday\Memories\Clusterer\DaySummaryStage\AwayFlagStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\DensityStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\GpsMetricsStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\InitializationStage;
use MagicSunday\Memories\Clusterer\DefaultDaySummaryBuilder;
use MagicSunday\Memories\Clusterer\DefaultHomeLocator;
use MagicSunday\Memories\Clusterer\DefaultVacationSegmentAssembler;
use MagicSunday\Memories\Clusterer\Service\BaseLocationResolver;
use MagicSunday\Memories\Clusterer\Service\PoiClassifier;
use MagicSunday\Memories\Clusterer\Service\RunDetector;
use MagicSunday\Memories\Clusterer\Service\StaypointDetector;
use MagicSunday\Memories\Clusterer\Service\TimezoneResolver;
use MagicSunday\Memories\Clusterer\Service\TransportDayExtender;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use MagicSunday\Memories\Clusterer\Service\VacationScoreCalculator;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Clusterer\VacationClusterStrategy;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\ClusterPersistenceService;
use MagicSunday\Memories\Service\Clusterer\Debug\VacationDebugContext;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberQualityRankingStage;
use MagicSunday\Memories\Service\Clusterer\Scoring\HolidayResolverInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\ClusterMemberSelectorInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\MemberSelectionContext;
use MagicSunday\Memories\Service\Clusterer\Selection\MemberSelectionResult;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicyProvider;
use MagicSunday\Memories\Service\Feed\CoverPickerInterface;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\VacationTestMemberSelector;
use MagicSunday\Memories\Utility\MediaMath;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;
use MagicSunday\Memories\Service\Clusterer\Title\RouteSummarizer;
use MagicSunday\Memories\Service\Clusterer\Title\LocalizedDateFormatter;
use MagicSunday\Memories\Service\Clusterer\Title\StoryTitleBuilder;
use Symfony\Component\Yaml\Yaml;

final class VacationClusterStrategyTest extends TestCase
{
    #[Test]
    public function ensuresDeterministicChronologicalOrdering(): void
    {
        $homeLocation = $this->makeLocation('home-reference', 'Zuhause', 52.5200, 13.4050, country: 'Germany');

        $noisyOrder = [
            $this->makeMediaFixture(
                2001,
                'later-b.jpg',
                new DateTimeImmutable('2024-01-05 10:00:00', new DateTimeZone('UTC')),
                52.5205,
                13.4055,
                $homeLocation,
            ),
            $this->makeMediaFixture(
                2003,
                'earlier.jpg',
                new DateTimeImmutable('2024-01-05 08:00:00', new DateTimeZone('UTC')),
                52.5206,
                13.4056,
                $homeLocation,
            ),
            $this->makeMediaFixture(
                2002,
                'later-a.jpg',
                new DateTimeImmutable('2024-01-05 10:00:00', new DateTimeZone('UTC')),
                52.5207,
                13.4057,
                $homeLocation,
            ),
        ];

        $home = [
            'lat' => $homeLocation->getLat(),
            'lon' => $homeLocation->getLon(),
            'radius_km' => 5.0,
            'country' => 'Germany',
            'timezone_offset' => 60,
        ];

        $capturedOrder = [];
        $receivedMembers = [];

        $homeLocator = new class($home) implements HomeLocatorInterface {
            /**
             * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
             */
            public function __construct(private array $home)
            {
            }

            public function determineHome(array $items): ?array
            {
                return $this->home;
            }

            public function getConfiguredHome(): ?array
            {
                return [
                    'lat' => $this->home['lat'],
                    'lon' => $this->home['lon'],
                    'radius_km' => $this->home['radius_km'],
                ];
            }
        };

        $daySummaryBuilder = new class($capturedOrder) implements DaySummaryBuilderInterface {
            /**
             * @param list<int> $capturedOrder
             */
            public function __construct(private array &$capturedOrder)
            {
            }

            public function buildDaySummaries(array $items, array $home): array
            {
                $this->capturedOrder = array_map(static fn (Media $media): int => $media->getId(), $items);

                return [
                    '2024-01-05' => [
                        'date' => '2024-01-05',
                        'members' => $items,
                        'gpsMembers' => $items,
                        'maxDistanceKm' => 0.0,
                        'avgDistanceKm' => 0.0,
                        'travelKm' => 0.0,
                        'maxSpeedKmh' => 0.0,
                        'avgSpeedKmh' => 0.0,
                        'hasHighSpeedTransit' => false,
                        'countryCodes' => [],
                        'timezoneOffsets' => [],
                        'localTimezoneIdentifier' => 'UTC',
                        'localTimezoneOffset' => null,
                        'tourismHits' => 0,
                        'poiSamples' => 0,
                        'tourismRatio' => 0.0,
                        'hasAirportPoi' => false,
                        'weekday' => 5,
                        'photoCount' => count($items),
                        'densityZ' => 0.0,
                        'isAwayCandidate' => false,
                        'sufficientSamples' => true,
                        'spotClusters' => [],
                        'spotNoise' => [],
                        'spotCount' => 0,
                        'spotNoiseSamples' => 0,
                        'spotDwellSeconds' => 0,
                        'staypoints' => [],
                        'staypointIndex' => StaypointIndex::empty(),
                        'staypointCounts' => [],
                        'dominantStaypoints' => [],
                        'transitRatio' => 0.0,
                        'poiDensity' => 0.0,
                        'baseLocation' => null,
                        'baseAway' => false,
                        'awayByDistance' => false,
                        'firstGpsMedia' => null,
                        'lastGpsMedia' => null,
                        'isSynthetic' => false,
                    ],
                ];
            }
        };

        $segmentAssembler = new class($receivedMembers) implements VacationSegmentAssemblerInterface {
            /**
             * @param list<int> $receivedMembers
             */
            public function __construct(private array &$receivedMembers)
            {
            }

            public function detectSegments(array $days, array $home): array
            {
                $day = $days['2024-01-05'];

                $this->receivedMembers = array_map(static fn (Media $media): int => $media->getId(), $day['members']);

                return [
                    new ClusterDraft(
                        'vacation',
                        ['stub' => true],
                        ['lat' => 0.0, 'lon' => 0.0],
                        $this->receivedMembers,
                    ),
                ];
            }
        };

        $strategy = new VacationClusterStrategy($homeLocator, $daySummaryBuilder, $segmentAssembler);

        $clusters = $strategy->draft($noisyOrder, Context::fromScope($noisyOrder));

        $expected = [2003, 2002, 2001];

        self::assertSame($expected, $capturedOrder);
        self::assertNotEmpty($clusters);
        self::assertSame($expected, $clusters[0]->getMembers());
        self::assertSame($expected, $receivedMembers);
    }

    #[Test]
    public function emitsWarningWhenConfiguredHomeDefaultsToZero(): void
    {
        $home = [
            'lat' => 0.0,
            'lon' => 0.0,
            'radius_km' => 15.0,
            'country' => null,
            'timezone_offset' => null,
        ];

        $homeLocator = new class($home) implements HomeLocatorInterface {
            public function __construct(private array $home)
            {
            }

            public function determineHome(array $items): ?array
            {
                return $this->home;
            }

            public function getConfiguredHome(): ?array
            {
                return [
                    'lat' => $this->home['lat'],
                    'lon' => $this->home['lon'],
                    'radius_km' => $this->home['radius_km'],
                ];
            }
        };

        $daySummaryBuilder = new class implements DaySummaryBuilderInterface {
            public function buildDaySummaries(array $items, array $home): array
            {
                return [];
            }
        };

        $segmentAssembler = new class implements VacationSegmentAssemblerInterface {
            public function detectSegments(array $days, array $home): array
            {
                return [];
            }
        };

        $events  = [];
        $emitter = new class($events) implements JobMonitoringEmitterInterface {
            /**
             * @param list<array{
             *     job: \Stringable|string|int|float|bool,
             *     status: \Stringable|string|int|float|bool,
             *     context: array<string, mixed>
             * }> $events
             */
            public function __construct(private array &$events)
            {
            }

            public function emit(\Stringable|string|int|float|bool $job, \Stringable|string|int|float|bool $status, array $context = []): void
            {
                $this->events[] = [
                    'job' => $job,
                    'status' => $status,
                    'context' => $context,
                ];
            }
        };

        $debugContext = new VacationDebugContext();

        $strategy = new VacationClusterStrategy(
            $homeLocator,
            $daySummaryBuilder,
            $segmentAssembler,
            $emitter,
            $debugContext,
        );

        $location = $this->makeLocation('null-island', 'Null Island', 0.0, 0.0, country: 'Ocean');
        $items    = [
            $this->makeMediaFixture(
                9001,
                'null-island.jpg',
                new DateTimeImmutable('2024-01-01 12:00:00', new DateTimeZone('UTC')),
                0.0,
                0.0,
                $location,
            ),
        ];

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertSame([], $clusters);

        $warnings = $debugContext->getWarnings();
        self::assertCount(1, $warnings);
        self::assertSame(
            'Konfiguration unvollständig: MEMORIES_HOME_LAT/MEMORIES_HOME_LON stehen auf 0/0. Bitte gültige Koordinaten und MEMORIES_HOME_RADIUS_KM setzen.',
            $warnings[0],
        );

        self::assertContains('home_warning', array_column($events, 'status'));
    }

    #[Test]
    public function emitsMonitoringEventsForClusterLifecycle(): void
    {
        $mediaA = $this->makeMediaFixture(
            3101,
            'vacation-monitoring-a.jpg',
            '2024-08-01 09:00:00',
        );

        $mediaB = $this->makeMediaFixture(
            3102,
            'vacation-monitoring-b.jpg',
            '2024-08-02 10:00:00',
        );

        $items = [$mediaA, $mediaB];

        $home = [
            'lat' => 48.1374,
            'lon' => 11.5755,
            'radius_km' => 7.5,
            'country' => 'Germany',
            'timezone_offset' => 120,
        ];

        $days = [
            '2024-08-01' => [
                'date' => '2024-08-01',
                'members' => [$mediaA],
                'gpsMembers' => [$mediaA],
                'maxDistanceKm' => 140.0,
                'avgDistanceKm' => 90.0,
                'travelKm' => 160.5,
                'maxSpeedKmh' => 110.0,
                'avgSpeedKmh' => 75.0,
                'hasHighSpeedTransit' => true,
                'countryCodes' => ['de' => true],
                'timezoneOffsets' => [120 => 1],
                'localTimezoneIdentifier' => 'Europe/Berlin',
                'localTimezoneOffset' => 120,
                'tourismHits' => 5,
                'poiSamples' => 6,
                'tourismRatio' => 0.8,
                'hasAirportPoi' => false,
                'weekday' => 4,
                'photoCount' => 1,
                'densityZ' => 1.4,
                'isAwayCandidate' => true,
                'sufficientSamples' => true,
                'spotClusters' => [],
                'spotNoise' => [],
                'spotCount' => 1,
                'spotNoiseSamples' => 0,
                'spotDwellSeconds' => 900,
                'staypoints' => [],
                'staypointIndex' => StaypointIndex::empty(),
                'staypointCounts' => [],
                'dominantStaypoints' => [],
                'transitRatio' => 0.0,
                'poiDensity' => 0.0,
                'baseLocation' => null,
                'baseAway' => true,
                'awayByDistance' => true,
                'firstGpsMedia' => $mediaA,
                'lastGpsMedia' => $mediaA,
                'isSynthetic' => false,
            ],
            '2024-08-02' => [
                'date' => '2024-08-02',
                'members' => [$mediaB],
                'gpsMembers' => [$mediaB],
                'maxDistanceKm' => 60.0,
                'avgDistanceKm' => 35.0,
                'travelKm' => 45.2,
                'maxSpeedKmh' => 80.0,
                'avgSpeedKmh' => 50.0,
                'hasHighSpeedTransit' => false,
                'countryCodes' => ['de' => true],
                'timezoneOffsets' => [120 => 1],
                'localTimezoneIdentifier' => 'Europe/Berlin',
                'localTimezoneOffset' => 120,
                'tourismHits' => 2,
                'poiSamples' => 3,
                'tourismRatio' => 0.4,
                'hasAirportPoi' => false,
                'weekday' => 5,
                'photoCount' => 1,
                'densityZ' => 0.8,
                'isAwayCandidate' => false,
                'sufficientSamples' => true,
                'spotClusters' => [],
                'spotNoise' => [],
                'spotCount' => 0,
                'spotNoiseSamples' => 0,
                'spotDwellSeconds' => 600,
                'staypoints' => [],
                'staypointIndex' => StaypointIndex::empty(),
                'staypointCounts' => [],
                'dominantStaypoints' => [],
                'transitRatio' => 0.0,
                'poiDensity' => 0.0,
                'baseLocation' => null,
                'baseAway' => false,
                'awayByDistance' => false,
                'firstGpsMedia' => $mediaB,
                'lastGpsMedia' => $mediaB,
                'isSynthetic' => false,
            ],
        ];

        $segments = [
            new ClusterDraft(
                'vacation',
                ['classification' => 'vacation'],
                ['lat' => 40.7128, 'lon' => -74.0060],
                [$mediaA->getId(), $mediaB->getId()],
            ),
        ];

        $events = [];

        $homeLocator = new class($home) implements HomeLocatorInterface {
            /**
             * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
             */
            public function __construct(private array $home)
            {
            }

            public function determineHome(array $items): ?array
            {
                return $this->home;
            }

            public function getConfiguredHome(): ?array
            {
                return [
                    'lat' => $this->home['lat'],
                    'lon' => $this->home['lon'],
                    'radius_km' => $this->home['radius_km'],
                ];
            }
        };

        $daySummaryBuilder = new class($days) implements DaySummaryBuilderInterface {
            /**
             * @param array<string, array<string, mixed>> $days
             */
            public function __construct(private array $days)
            {
            }

            public function buildDaySummaries(array $items, array $home): array
            {
                return $this->days;
            }
        };

        $segmentAssembler = new class($segments) implements VacationSegmentAssemblerInterface {
            /**
             * @param list<ClusterDraft> $segments
             */
            public function __construct(private array $segments)
            {
            }

            public function detectSegments(array $days, array $home): array
            {
                return $this->segments;
            }
        };

        $emitter = new class($events) implements JobMonitoringEmitterInterface {
            /**
             * @param list<array{
             *     job: \Stringable|string|int|float|bool,
             *     status: \Stringable|string|int|float|bool,
             *     context: array<string, mixed>
             * }> $events
             */
            public function __construct(private array &$events)
            {
            }

            public function emit(\Stringable|string|int|float|bool $job, \Stringable|string|int|float|bool $status, array $context = []): void
            {
                $this->events[] = [
                    'job' => $job,
                    'status' => $status,
                    'context' => $context,
                ];
            }
        };

        $strategy = new VacationClusterStrategy($homeLocator, $daySummaryBuilder, $segmentAssembler, $emitter);

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertSame($segments, $clusters);

        self::assertCount(5, $events);

        self::assertSame('cluster.vacation', $events[0]['job']);
        self::assertSame('start', $events[0]['status']);
        self::assertSame(2, $events[0]['context']['total_count']);

        self::assertSame('filtered', $events[1]['status']);
        self::assertSame(2, $events[1]['context']['timestamped_count']);

        self::assertSame('home_determined', $events[2]['status']);
        self::assertSame($home['radius_km'], $events[2]['context']['home_radius_km']);

        self::assertSame('days_aggregated', $events[3]['status']);
        self::assertSame(2, $events[3]['context']['day_count']);
        self::assertSame(1, $events[3]['context']['away_day_count']);
        self::assertGreaterThan(0.0, $events[3]['context']['total_travel_km']);

        self::assertSame('completed', $events[4]['status']);
        self::assertSame(1, $events[4]['context']['segment_count']);
        self::assertSame(2, $events[4]['context']['timestamped_count']);
    }

    #[Test]
    public function classifiesExtendedInternationalVacation(): void
    {
        $helper   = LocationHelper::createDefault();
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
            minItemsPerDay: 4,
        );

        $items        = [];
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

        $id        = 1000;
        $startHome = new DateTimeImmutable('2024-05-20 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 8; ++$i) {
            $day = $startHome->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[]   = $this->makeMediaFixture(
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

        $tripStart       = new DateTimeImmutable('2024-06-10 07:00:00', new DateTimeZone('UTC'));
        $vacationDayById = [];
        foreach ($tracks as $dayIndex => $coordinates) {
            $dayStart = $tripStart->add(new DateInterval('P' . $dayIndex . 'D'));
            foreach ($coordinates as $pointIndex => $data) {
                $timestamp = $dayStart->add(new DateInterval('PT' . ($pointIndex * 4) . 'H'));
                $media     = $this->makeMediaFixture(
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
                $items[]                          = $media;
            }

            $nightTimestamp = $dayStart->setTime(23, 30, 0);
            $nightMedia     = $this->makeMediaFixture(
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
            $items[]                               = $nightMedia;
        }

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('vacation', $cluster->getAlgorithm());

        $params = $cluster->getParams();
        self::assertSame('short_trip', $params['classification']);
        self::assertSame('Kurztrip', $params['classification_label']);
        self::assertGreaterThanOrEqual(6.0, $params['score']);
        self::assertTrue($params['country_change']);
        self::assertTrue($params['timezone_change']);
        self::assertIsBool($params['airport_transfer']);
        self::assertArrayHasKey('spot_count', $params);
        self::assertArrayHasKey('spot_cluster_days', $params);
        self::assertArrayHasKey('spot_dwell_hours', $params);
        self::assertArrayHasKey('spot_exploration_bonus', $params);
        self::assertSame(5, $params['work_day_penalty_days']);
        self::assertSame(2.0, $params['work_day_penalty_score']);
        self::assertArrayHasKey('countries', $params);
        self::assertSame(['it'], $params['countries']);
        self::assertSame([120], $params['timezones']);
        self::assertSame('Roma', $params['place_city']);
        self::assertSame('Lazio', $params['place_region']);
        self::assertSame('Italy', $params['place_country']);
        self::assertArrayHasKey('place', $params);
        self::assertNotSame('', $params['place']);

        $centroid           = $cluster->getCentroid();
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

        self::assertGreaterThanOrEqual(4, count($coverage));
        foreach ([0, 1, 2, 3] as $requiredDay) {
            self::assertArrayHasKey($requiredDay, $coverage);
        }
    }

    #[Test]
    public function keepsBalancedVacationMembersWhenClamped(): void
    {
        $dayMembers = [
            0 => array_values(range(5000, 5019)),
            1 => array_values(range(6000, 6004)),
            2 => array_values(range(7000, 7004)),
        ];

        /** @var array<int,int> $dayByMember */
        $dayByMember      = [];
        $maxMembersPerDay = 0;
        foreach ($dayMembers as $day => $ids) {
            $count = count($ids);
            if ($count > $maxMembersPerDay) {
                $maxMembersPerDay = $count;
            }

            foreach ($ids as $id) {
                $dayByMember[$id] = $day;
            }
        }

        $balancedMembers = [];
        for ($index = 0; $index < $maxMembersPerDay; ++$index) {
            foreach ($dayMembers as $ids) {
                if (isset($ids[$index])) {
                    $balancedMembers[] = $ids[$index];
                }
            }
        }

        $mediaById     = [];
        $baseTimestamp = new DateTimeImmutable('2024-07-01 09:00:00', new DateTimeZone('UTC'));
        foreach ($dayMembers as $day => $ids) {
            foreach ($ids as $offset => $id) {
                $timestamp = $baseTimestamp
                    ->add(new DateInterval('P' . $day . 'D'))
                    ->add(new DateInterval('PT' . ($offset % 6) . 'H'));

                $media = $this->makeMediaFixture(
                    $id,
                    sprintf('vacation-balance-%d-%d.jpg', $day, $offset),
                    $timestamp,
                );

                if ($day === 0) {
                    $media->setWidth(7200);
                    $media->setHeight(4800);
                    $media->setSharpness(0.92);
                    $media->setIso(80);
                    $media->setBrightness(0.64);
                    $media->setContrast(0.72);
                    $media->setEntropy(0.70);
                    $media->setColorfulness(0.74);
                } elseif ($day === 1) {
                    $media->setWidth(4600);
                    $media->setHeight(3200);
                    $media->setSharpness(0.68);
                    $media->setIso(160);
                    $media->setBrightness(0.56);
                    $media->setContrast(0.60);
                    $media->setEntropy(0.58);
                    $media->setColorfulness(0.60);
                } else {
                    $media->setWidth(3000);
                    $media->setHeight(2000);
                    $media->setSharpness(0.38);
                    $media->setIso(400);
                    $media->setBrightness(0.48);
                    $media->setContrast(0.46);
                    $media->setEntropy(0.44);
                    $media->setColorfulness(0.42);
                }

                $media->setPhash(sprintf('day-%d-phash-%d', $day, $offset));
                $media->setPhashPrefix(sprintf('day-%d-phash-%d', $day, $offset));
                $media->setDhash(sprintf('day-%d-dhash-%d', $day, $offset));
                $media->setBurstUuid(sprintf('day-%d-burst-%d', $day, $offset));

                $mediaById[$id] = $media;
            }
        }

        $lookup = new class($mediaById) implements MemberMediaLookupInterface {
            /**
             * @param array<int, Media> $map
             */
            public function __construct(private readonly array $map)
            {
            }

            public function findByIds(array $ids, bool $onlyVideos = false): array
            {
                $result = [];
                foreach ($ids as $id) {
                    $media = $this->map[$id] ?? null;
                    if ($media instanceof Media) {
                        $result[] = $media;
                    }
                }

                return $result;
            }
        };

        $stage = new MemberQualityRankingStage($lookup, 12.0);
        $draft = new ClusterDraft(
            algorithm: 'vacation',
            params: [
                'quality_avg'        => 0.6,
                'aesthetics_score'   => 0.55,
                'quality_resolution' => 0.6,
                'quality_sharpness'  => 0.58,
                'quality_iso'        => 0.6,
            ],
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: $balancedMembers,
        );

        $stage->process([$draft]);

        $service = $this->createPersistenceService();

        $reflection = new ReflectionClass(ClusterPersistenceService::class);
        $resolve    = $reflection->getMethod('resolveOrderedMembers');
        $resolve->setAccessible(true);

        /** @var list<int> $resolved */
        $resolved = $resolve->invoke($service, $draft);

        $params        = $draft->getParams();
        $memberQuality = $params['member_quality']['ordered'] ?? [];
        if (is_array($memberQuality) && $memberQuality !== []) {
            self::assertNotSame($memberQuality, $draft->getMembers());
        }

        self::assertSame($draft->getMembers(), $resolved);

        $clamp = $reflection->getMethod('clampMembers');
        $clamp->setAccessible(true);

        /** @var list<int> $clamped */
        $clamped = $clamp->invoke($service, $resolved);

        self::assertCount(20, $clamped);

        $daysSeen = [];
        foreach ($clamped as $memberId) {
            $day = $dayByMember[$memberId] ?? null;
            if ($day !== null) {
                $daysSeen[$day] = true;
            }
        }

        $coveredDays = array_keys($daysSeen);
        sort($coveredDays);

        self::assertSame([0, 1, 2], $coveredDays);
    }

    #[Test]
    public function groupsMediaByLocalTimezoneAcrossOffsets(): void
    {
        $helper   = LocationHelper::createDefault();
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
        $helper   = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(['2024-07-05']),
            timezone: 'UTC',
            defaultHomeRadiusKm: 10.0,
            minAwayDistanceKm: 60.0,
            movementThresholdKm: 500.0,
            minItemsPerDay: 4,
        );

        $items        = [];
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

        $id            = 2000;
        $homeSeedStart = new DateTimeImmutable('2024-07-04 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 2; ++$i) {
            $day = $homeSeedStart->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[]   = $this->makeMediaFixture(
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
            $items[]  = $this->makeMediaFixture(
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
                $dayStart->add(new DateInterval('PT3H'))->format('Y-m-d H:i:s'),
                53.7120,
                10.0820,
                $getawayLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('weekend-trip-%d-2.jpg', $day),
                $dayStart->add(new DateInterval('PT6H'))->format('Y-m-d H:i:s'),
                53.7200,
                10.0600,
                $villageLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(120);
                }
            );
            $nightShot = $dayStart->setTime(22, 45, 0);
            $items[]   = $this->makeMediaFixture(
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

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertSame([], $clusters);
    }

    #[Test]
    public function awardsHolidayBonusOnWeekdays(): void
    {
        $helper       = LocationHelper::createDefault();
        $holidayDates = ['2024-12-23', '2024-12-24'];
        $strategy     = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver($holidayDates),
            timezone: 'UTC',
            defaultHomeRadiusKm: 10.0,
            minAwayDistanceKm: 60.0,
            movementThresholdKm: 500.0,
            minItemsPerDay: 4,
        );

        $items        = [];
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

        $id            = 4000;
        $homeSeedStart = new DateTimeImmutable('2024-12-20 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $day = $homeSeedStart->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[]   = $this->makeMediaFixture(
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
            $items[]  = $this->makeMediaFixture(
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
                $dayStart->add(new DateInterval('PT3H'))->format('Y-m-d H:i:s'),
                $holidayLocation->getLat() + 0.004,
                $holidayLocation->getLon() + 0.004,
                $holidayLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
            $items[] = $this->makeMediaFixture(
                ++$id,
                sprintf('holiday-trip-%d-2.jpg', $day),
                $dayStart->add(new DateInterval('PT6H'))->format('Y-m-d H:i:s'),
                $villageLocation->getLat(),
                $villageLocation->getLon(),
                $villageLocation,
                static function (Media $media): void {
                    $media->setTimezoneOffsetMin(60);
                }
            );
            $nightShot = $dayStart->setTime(22, 45, 0);
            $items[]   = $this->makeMediaFixture(
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

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];
        $params  = $cluster->getParams();

        self::assertSame('day_trip', $params['classification']);
        self::assertSame('Tagesausflug', $params['classification_label']);
        self::assertSame(4, $params['weekend_holiday_days']);
        self::assertSame(1.4, $params['weekend_holiday_bonus']);
        self::assertGreaterThanOrEqual(5.0, $params['score']);
        self::assertLessThanOrEqual(2, $params['work_day_penalty_days']);
        self::assertLessThanOrEqual(0.8, $params['work_day_penalty_score']);
        self::assertArrayHasKey('countries', $params);
        self::assertSame(['de'], $params['countries']);
        self::assertSame([60], $params['timezones']);
    }

    #[Test]
    public function includesAirportBufferDayAtSegmentEdges(): void
    {
        $helper   = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
            timezone: 'UTC',
            defaultHomeRadiusKm: 15.0,
            minAwayDistanceKm: 80.0,
            movementThresholdKm: 25.0,
            minItemsPerDay: 4,
        );

        $items        = [];
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

        $id            = 5000;
        $homeSeedStart = new DateTimeImmutable('2024-07-01 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 5; ++$i) {
            $day = $homeSeedStart->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[]   = $this->makeMediaFixture(
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
                $items[]   = $this->makeMediaFixture(
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
            $items[]        = $this->makeMediaFixture(
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
            $items[]   = $this->makeMediaFixture(
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
                $items[]   = $this->makeMediaFixture(
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

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        $params = $cluster->getParams();
        self::assertSame('day_trip', $params['classification']);
        self::assertSame('Tagesausflug', $params['classification_label']);
        self::assertSame(3, $params['away_days']);
        self::assertTrue($params['airport_transfer']);
        self::assertSame(3, $params['work_day_penalty_days']);
        self::assertSame(1.2, $params['work_day_penalty_score']);
        self::assertSame('France', $params['place_country']);
        self::assertGreaterThanOrEqual(5.0, $params['score']);
    }

    #[Test]
    public function keepsSparsePhotoDaysWithinVacationRuns(): void
    {
        $helper   = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
            timezone: 'UTC',
            defaultHomeRadiusKm: 12.0,
            minAwayDistanceKm: 90.0,
            movementThresholdKm: 25.0,
            minItemsPerDay: 4,
        );

        $items        = [];
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

        $id            = 6000;
        $homeSeedStart = new DateTimeImmutable('2024-06-01 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 4; ++$i) {
            $day = $homeSeedStart->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[]   = $this->makeMediaFixture(
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

        $tripStart      = new DateTimeImmutable('2024-06-10 08:00:00', new DateTimeZone('UTC'));
        $sparsePhotoIds = [];
        for ($day = 0; $day < 3; ++$day) {
            $dayStart   = $tripStart->add(new DateInterval('P' . $day . 'D'));
            $photoCount = $day === 1 ? 2 : 4;

            for ($photo = 0; $photo < $photoCount; ++$photo) {
                $timestamp = $dayStart->add(new DateInterval('PT' . ($photo * 4) . 'H'));
                $items[]   = $this->makeMediaFixture(
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
                $items[]        = $this->makeMediaFixture(
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

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        $params = $cluster->getParams();
        self::assertSame('day_trip', $params['classification']);
        self::assertSame('Tagesausflug', $params['classification_label']);
        self::assertSame(4, $params['away_days']);
        self::assertSame(3, $params['nights']);
        self::assertSame(4, $params['work_day_penalty_days']);
        self::assertSame(1.6, $params['work_day_penalty_score']);
        self::assertGreaterThanOrEqual(4.5, $params['score']);

        foreach ($sparsePhotoIds as $photoId) {
            self::assertContains($photoId, $cluster->getMembers());
        }
    }

    #[Test]
    public function recognisesMultiSpotExplorationWithinTrip(): void
    {
        $helper   = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
            timezone: 'UTC',
            defaultHomeRadiusKm: 12.0,
            minAwayDistanceKm: 90.0,
            movementThresholdKm: 25.0,
            minItemsPerDay: 4,
        );

        $items        = [];
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

        $id            = 9000;
        $homeSeedStart = new DateTimeImmutable('2024-04-10 09:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $day = $homeSeedStart->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[]   = $this->makeMediaFixture(
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
                    $items[]   = $this->makeMediaFixture(
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

            $nightLocation  = $clustersForDay[1]['location'];
            $nightPoint     = $clustersForDay[1]['points'][1];
            $nightTimestamp = $dayStart->setTime(22, 30, 0);
            $items[]        = $this->makeMediaFixture(
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
            $items[]   = $this->makeMediaFixture(
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

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        $params = $cluster->getParams();
        self::assertSame('short_trip', $params['classification']);
        self::assertSame('Kurztrip', $params['classification_label']);
        self::assertGreaterThanOrEqual(5.5, $params['score']);
        self::assertSame(4, $params['spot_count']);
        self::assertSame(2, $params['spot_cluster_days']);
        self::assertGreaterThan(0.0, $params['spot_exploration_bonus']);
        self::assertGreaterThan(0.0, $params['spot_dwell_hours']);
        self::assertSame(3, $params['work_day_penalty_days']);
        self::assertSame(1.2, $params['work_day_penalty_score']);
    }

    #[Test]
    public function keepsDstTransitionWithinSingleVacationRun(): void
    {
        $helper   = LocationHelper::createDefault();
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
        $id    = 6000;

        $homeBaseline = new DateTimeImmutable('2024-03-24 09:00:00', new DateTimeZone('Europe/Berlin'));
        for ($i = 0; $i < 3; ++$i) {
            $day = $homeBaseline->add(new DateInterval('P' . $i . 'D'));
            for ($sample = 0; $sample < 3; ++$sample) {
                $timestamp = $day->setTime(9 + ($sample * 3), 0, 0);
                $items[]   = $this->makeMediaFixture(
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

        $tripStart   = new DateTimeImmutable('2024-03-30 09:00:00', new DateTimeZone('Europe/Berlin'));
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
            $nightId        = ++$id;
            $items[]        = $this->makeMediaFixture(
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
                $items[]   = $this->makeMediaFixture(
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

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertSame([], $clusters);
    }

    #[Test]
    public function ignoresExtremeGpsOutliersWhenScoringAwayDays(): void
    {
        $helper   = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
            timezone: 'UTC',
            defaultHomeRadiusKm: 12.0,
            minAwayDistanceKm: 80.0,
            movementThresholdKm: 25.0,
            minItemsPerDay: 4,
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
        $id    = 9000;
        $start = new DateTimeImmutable('2024-08-01 09:00:00', new DateTimeZone('UTC'));

        for ($day = 0; $day < 3; ++$day) {
            $dayStart = $start->add(new DateInterval('P' . $day . 'D'));

            for ($photo = 0; $photo < 3; ++$photo) {
                $timestamp = $dayStart->add(new DateInterval('PT' . ($photo * 3) . 'H'));
                $items[]   = $this->makeMediaFixture(
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
            $items[]          = $this->makeMediaFixture(
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

        $clusters = $strategy->draft($items, Context::fromScope($items));

        self::assertSame([], $clusters);
    }

    #[Test]
    public function returnsEmptyWhenHomeCannotBeDerived(): void
    {
        $helper   = LocationHelper::createDefault();
        $strategy = $this->makeStrategy(
            locationHelper: $helper,
            holidayResolver: $this->createHolidayResolver(),
            timezone: 'UTC',
            defaultHomeRadiusKm: 10.0,
            minAwayDistanceKm: 60.0,
            movementThresholdKm: 30.0,
            minItemsPerDay: 4,
        );

        $items    = [];
        $location = $this->makeLocation('no-home', 'Anywhere', 48.2082, 16.3738, country: 'Austria', configure: static function (Location $loc): void {
            $loc->setCountryCode('AT');
            $loc->setCategory('tourism');
        });

        $base = new DateTimeImmutable('2024-08-10 12:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 4; ++$i) {
            $timestamp = $base->add(new DateInterval('P' . $i . 'D'));
            $items[]   = $this->makeMediaFixture(
                3000 + $i,
                sprintf('no-home-%d.jpg', $i),
                $timestamp->format('Y-m-d H:i:s'),
                48.2082,
                16.3738,
                $location
            );
        }

        self::assertSame([], $strategy->draft($items, Context::fromScope($items)));
    }

    private function makeStrategy(
        LocationHelper $locationHelper,
        ?HolidayResolverInterface $holidayResolver = null,
        string $timezone = 'Europe/Berlin',
        float $defaultHomeRadiusKm = 15.0,
        float $minAwayDistanceKm = 140.0,
        float $movementThresholdKm = 35.0,
        int $minItemsPerDay = 4,
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
        $dayBuilder       = new DefaultDaySummaryBuilder([
            new InitializationStage($timezoneResolver, new PoiClassifier(), $timezone),
            new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector(), $gpsOutlierRadiusKm, $gpsOutlierMinSamples, $minItemsPerDay),
            new DensityStage(),
            new AwayFlagStage($timezoneResolver, new BaseLocationResolver()),
        ]);

        $transportExtender = new TransportDayExtender();
        $runDetector       = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: $minAwayDistanceKm,
            minItemsPerDay: $minItemsPerDay,
        );

        $selectionOptions  = new VacationSelectionOptions(targetTotal: 32, maxPerDay: 8);
        $selectionProfiles = new SelectionProfileProvider($selectionOptions, 'vacation');
        $routeSummarizer   = new RouteSummarizer();
        $dateFormatter     = new LocalizedDateFormatter();
        $storyTitleBuilder = new StoryTitleBuilder($routeSummarizer, $dateFormatter);

        $scoreCalculator = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            memberSelector: new VacationTestMemberSelector(),
            selectionProfiles: $selectionProfiles,
            storyTitleBuilder: $storyTitleBuilder,
            holidayResolver: $holidayResolver ?? $this->createHolidayResolver(),
            timezone: $timezone,
            movementThresholdKm: $movementThresholdKm,
            minAwayDays: 2,
            minItemsPerDay: $minItemsPerDay,
            minimumMemberFloor: 0,
        );

        $segmentAssembler = new DefaultVacationSegmentAssembler($runDetector, $scoreCalculator, $storyTitleBuilder);

        return new VacationClusterStrategy($homeLocator, $dayBuilder, $segmentAssembler);
    }

    /**
     * @param list<int> $memberIds
     *
     * @return list<int>
     */
    private function clampMemberList(array $memberIds, int $limit): array
    {
        $service = $this->createPersistenceService($limit);

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

    private function createPersistenceService(int $maxMembers = 20): ClusterPersistenceService
    {
        $lookup = new class implements MemberMediaLookupInterface {
            public function findByIds(array $ids, bool $onlyVideos = false): array
            {
                return [];
            }
        };

        $selector = new class implements ClusterMemberSelectorInterface {
            public function select(string $algorithm, array $memberIds, ?MemberSelectionContext $context = null): MemberSelectionResult
            {
                return new MemberSelectionResult($memberIds, ['selector' => 'spy']);
            }
        };

        return new ClusterPersistenceService(
            $this->createStub(EntityManagerInterface::class),
            $lookup,
            $selector,
            $this->createPolicyProvider(),
            $this->createStub(CoverPickerInterface::class),
            250,
            $maxMembers,
        );
    }

    private function createPolicyProvider(): SelectionPolicyProvider
    {
        $config = Yaml::parseFile(dirname(__DIR__, 3) . '/config/parameters/selection.yaml');
        $parameters = $config['parameters'] ?? [];

        return new SelectionPolicyProvider(
            $parameters['memories.selection.profiles'] ?? [],
            $parameters['memories.selection.default_profile'] ?? 'default',
            $parameters['memories.selection.algorithm_profiles'] ?? [],
            $parameters['memories.selection.profile_constraints'] ?? [],
        );
    }
}
