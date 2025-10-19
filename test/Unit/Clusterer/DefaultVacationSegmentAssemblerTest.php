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
use MagicSunday\Memories\Clusterer\Contract\VacationRunDetectorInterface;
use MagicSunday\Memories\Clusterer\Contract\VacationScoreCalculatorInterface;
use MagicSunday\Memories\Clusterer\DaySummaryStage\AwayFlagStage;
use MagicSunday\Memories\Clusterer\DaySummaryStage\CohortPresenceStage;
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
use MagicSunday\Memories\Clusterer\Support\StaypointIndex;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\VacationTestMemberSelector;
use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;
use MagicSunday\Memories\Service\Clusterer\Title\RouteSummarizer;
use MagicSunday\Memories\Service\Clusterer\Title\LocalizedDateFormatter;
use MagicSunday\Memories\Service\Clusterer\Title\StoryTitleBuilder;

final class DefaultVacationSegmentAssemblerTest extends TestCase
{
    #[Test]
    public function detectSegmentsMergesDstTransitionDays(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $homeLocator    = new DefaultHomeLocator(
            timezone: 'Europe/Berlin',
            defaultHomeRadiusKm: 12.0,
            homeLat: 52.5200,
            homeLon: 13.4050,
            homeRadiusKm: 12.0,
        );

        $timezoneResolver = new TimezoneResolver('Europe/Berlin');
        $dayBuilder       = new DefaultDaySummaryBuilder([
            new InitializationStage($timezoneResolver, new PoiClassifier(), 'Europe/Berlin'),
            new CohortPresenceStage(),
            new GpsMetricsStage(new GeoDbscanHelper(), new StaypointDetector(), 1.0, 3, 2),
            new DensityStage(),
            new AwayFlagStage($timezoneResolver, new BaseLocationResolver()),
        ]);

        $transportExtender = new TransportDayExtender();
        $runDetector       = new RunDetector(
            transportDayExtender: $transportExtender,
            minAwayDistanceKm: 80.0,
            minItemsPerDay: 2,
        );
        $selectionOptions   = new VacationSelectionOptions(targetTotal: 24, maxPerDay: 6);
        $selectionProfiles  = new SelectionProfileProvider($selectionOptions, 'vacation');
        $routeSummarizer    = new RouteSummarizer();
        $dateFormatter      = new LocalizedDateFormatter();
        $storyTitleBuilder  = new StoryTitleBuilder($routeSummarizer, $dateFormatter);

        $scoreCalculator = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            memberSelector: new VacationTestMemberSelector(),
            selectionProfiles: $selectionProfiles,
            storyTitleBuilder: $storyTitleBuilder,
            holidayResolver: new NullHolidayResolver(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 25.0,
            minAwayDays: 2,
            minItemsPerDay: 4,
            minimumMemberFloor: 0,
            enforceDynamicMinimum: false,
        );

        $assembler = new DefaultVacationSegmentAssembler($runDetector, $scoreCalculator, $storyTitleBuilder);

        $tripLocation = $this->makeLocation('trip-lisbon', 'Lisboa, Portugal', 38.7223, -9.1393, country: 'Portugal', configure: static function (Location $loc): void {
            $loc->setCategory('tourism');
            $loc->setType('attraction');
            $loc->setCountryCode('PT');
        });

        $items = [];
        $id    = 1000;

        $tripStart = new DateTimeImmutable('2024-03-29 09:00:00', new DateTimeZone('Europe/Berlin'));
        $offsets   = [0, 0, 60, 60, 60];
        foreach ($offsets as $dayIndex => $offset) {
            $dayStart = $tripStart->add(new DateInterval('P' . $dayIndex . 'D'));
            for ($photo = 0; $photo < 4; ++$photo) {
                $timestamp = $dayStart->add(new DateInterval('PT' . ($photo * 6) . 'H'));
                $items[]   = $this->makeMediaFixture(
                    ++$id,
                    sprintf('trip-day-%d-%d.jpg', $dayIndex, $photo),
                    $timestamp,
                    $tripLocation->getLat() + ($photo * 0.005),
                    $tripLocation->getLon() + ($photo * 0.005),
                    $tripLocation,
                    static function (Media $media) use ($offset): void {
                        $media->setTimezoneOffsetMin($offset);
                        $media->setHasFaces(true);
                        $media->setFacesCount(1);
                        $media->setQualityScore(0.9);
                    }
                );
            }
        }

        $home = $homeLocator->determineHome($items);
        self::assertNotNull($home);

        $days     = $dayBuilder->buildDaySummaries($items, $home);
        $clusters = $assembler->detectSegments($days, $home);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertSame('vacation', $cluster->getAlgorithm());
        $params = $cluster->getParams();
        // Score thresholds may downgrade non-weekend runs to "weekend_getaway" when core metrics
        // fall below the "vacation" band, which is acceptable for this regression scenario.
        self::assertContains($params['classification'], ['vacation', 'weekend_getaway']);
        self::assertSame(6, $params['away_days']);
        self::assertEqualsCanonicalizing([0, 60], $params['timezones']);
        self::assertArrayHasKey('countries', $params);
        self::assertSame(['pt'], $params['countries']);
        self::assertArrayHasKey('member_selection', $params);
        self::assertArrayHasKey('day_segments', $params);
        self::assertNotSame([], $params['day_segments']);
        $segmentSample = reset($params['day_segments']);
        self::assertIsArray($segmentSample);
        self::assertArrayHasKey('category', $segmentSample);
        self::assertArrayHasKey('vacation_title', $params);
        self::assertArrayHasKey('vacation_subtitle', $params);
        self::assertStringContainsString('Personenanteil:', (string) $params['vacation_subtitle']);
    }

    #[Test]
    public function classifyRunDaysIncorporatesTravelScore(): void
    {
        $runDetector = new class implements VacationRunDetectorInterface {
            public function detectVacationRuns(array $days, array $home): array
            {
                return [array_keys($days)];
            }
        };

        $scoreCalculator = new class implements VacationScoreCalculatorInterface {
            /**
             * @var list<array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}>>
             */
            public array $receivedContexts = [];

            public function buildDraft(array $dayKeys, array $days, array $home, array $dayContext = []): ?ClusterDraft
            {
                $this->receivedContexts[] = $dayContext;

                return null;
            }
        };

        $storyTitleBuilder = new StoryTitleBuilder(new RouteSummarizer(), new LocalizedDateFormatter());
        $assembler         = new DefaultVacationSegmentAssembler($runDetector, $scoreCalculator, $storyTitleBuilder);

        $home = [
            'lat'             => 0.0,
            'lon'             => 0.0,
            'radius_km'       => 1.0,
            'country'         => null,
            'timezone_offset' => 0,
        ];

        $travelByDay = [
            '2024-07-01' => 10.0,
            '2024-07-02' => 200.0,
            '2024-07-03' => 5.0,
        ];

        $days = [];
        foreach ($travelByDay as $day => $travelKm) {
            $moment   = new DateTimeImmutable($day . 'T08:00:00+00:00');
            $member   = $this->createConfiguredStub(Media::class, [
                'hasFaces'        => false,
                'getQualityScore' => 0.7,
                'getTakenAt'      => $moment,
            ]);

            $days[$day] = [
                'date'                  => $day,
                'members'               => [$member],
                'gpsMembers'            => [$member],
                'maxDistanceKm'         => 0.0,
                'avgDistanceKm'         => 0.0,
                'travelKm'              => $travelKm,
                'maxSpeedKmh'           => 0.0,
                'avgSpeedKmh'           => 0.0,
                'hasHighSpeedTransit'   => false,
                'countryCodes'          => [],
                'timezoneOffsets'       => [],
                'localTimezoneIdentifier' => 'Europe/Berlin',
                'localTimezoneOffset'   => 0,
                'tourismHits'           => 0,
                'poiSamples'            => 0,
                'tourismRatio'          => 0.3,
                'hasAirportPoi'         => false,
                'weekday'               => (int) $moment->format('N'),
                'photoCount'            => 1,
                'densityZ'              => 0.0,
                'isAwayCandidate'       => true,
                'sufficientSamples'     => true,
                'spotClusters'          => [],
                'spotNoise'             => [],
                'spotCount'             => 1,
                'spotNoiseSamples'      => 0,
                'spotDensity'           => 0.0,
                'spotDwellSeconds'      => 0,
                'staypoints'            => [],
                'staypointIndex'        => StaypointIndex::empty(),
                'staypointCounts'       => [],
                'dominantStaypoints'    => [],
                'transitRatio'          => 0.0,
                'poiDensity'            => 0.2,
                'cohortPresenceRatio'   => 0.4,
                'cohortMembers'         => [],
                'baseLocation'          => null,
                'baseAway'              => true,
                'awayByDistance'        => true,
                'firstGpsMedia'         => $member,
                'lastGpsMedia'          => $member,
                'isSynthetic'           => false,
            ];
        }

        $assembler->detectSegments($days, $home);

        self::assertCount(1, $scoreCalculator->receivedContexts);
        $context = $scoreCalculator->receivedContexts[0];

        self::assertSame('core', $context['2024-07-02']['category']);
        self::assertSame('core', $context['2024-07-01']['category']);
        self::assertSame('peripheral', $context['2024-07-03']['category']);

        self::assertArrayHasKey('travel_score', $context['2024-07-02']['metrics']);
        self::assertEqualsWithDelta(0.8, $context['2024-07-02']['metrics']['travel_score'], 0.001);
        self::assertEqualsWithDelta(0.04, $context['2024-07-01']['metrics']['travel_score'], 0.001);
        self::assertEqualsWithDelta(0.02, $context['2024-07-03']['metrics']['travel_score'], 0.001);
    }
}
