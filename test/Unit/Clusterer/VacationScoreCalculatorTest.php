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
use MagicSunday\Memories\Clusterer\Service\VacationScoreCalculator;
use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use MagicSunday\Memories\Clusterer\Support\StaypointIndex;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\ClusterPersistenceService;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Clusterer\Scoring\HolidayResolverInterface;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Service\Clusterer\Title\LocalizedDateFormatter;
use MagicSunday\Memories\Service\Clusterer\Title\RouteSummarizer;
use MagicSunday\Memories\Service\Clusterer\Title\StoryTitleBuilder;
use MagicSunday\Memories\Service\Feed\CoverPickerInterface;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\Contract\LocationLabelResolverInterface;
use MagicSunday\Memories\Utility\Contract\PoiContextAnalyzerInterface;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\VacationTestMemberSelector;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\RecordingMonitoringEmitter;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * @covers \MagicSunday\Memories\Clusterer\Service\VacationScoreCalculator
 */
final class VacationScoreCalculatorTest extends TestCase
{
    #[Test]
    public function buildDraftScoresMultiDayTripAsVacation(): void
    {
        $locationHelper    = LocationHelper::createDefault();
        $selectionOptions  = new VacationSelectionOptions(targetTotal: 40, maxPerDay: 8);
        $referenceDate     = new DateTimeImmutable('2024-04-15 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator        = $this->createCalculator(
            locationHelper: $locationHelper,
            options: $selectionOptions,
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            referenceDate: $referenceDate,
            minimumMemberFloor: 0,
            enforceDynamicMinimum: false,
        );

        $lisbonLocation = (new Location(
            provider: 'test',
            providerPlaceId: 'lisbon',
            displayName: 'Lisboa, Portugal',
            lat: 38.7223,
            lon: -9.1393,
            cell: 'cell-lisbon',
        ))
            ->setCity('Lisbon')
            ->setState('Lisbon District')
            ->setCountry('Portugal')
            ->setCountryCode('PT');

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $start    = new DateTimeImmutable('2024-04-01 09:00:00');
        $days     = [];
        $poiTypes = ['museum', 'park', 'aquarium'];
        for ($i = 0; $i < 3; ++$i) {
            $dayDate       = $start->add(new DateInterval('P' . $i . 'D'));
            $dayLocation   = clone $lisbonLocation;
            $dayLocation->setType($poiTypes[$i % count($poiTypes)])->setCategory('tourism');
            $members       = $this->makeMembersForDay($i, $dayDate, 4, $dayLocation);
            $dayKey        = $dayDate->format('Y-m-d');
            $ratio         = 0.2 + (0.2 * $i);
            $cohortMembers = match ($i) {
                0       => [101 => 2],
                1       => [101 => 1, 202 => 3],
                default => [202 => 1],
            };

            $staypoints   = [];
            $firstMember  = $members[0];
            $startTime    = $firstMember->getTakenAt();
            $dwellSeconds = 14400 + ($i * 1800);
            if ($startTime instanceof DateTimeImmutable) {
                $lat         = $firstMember->getGpsLat() ?? 38.7223;
                $lon         = $firstMember->getGpsLon() ?? -9.1393;
                $staypoints[] = [
                    'lat'   => (float) $lat,
                    'lon'   => (float) $lon,
                    'start' => $startTime->getTimestamp(),
                    'end'   => $startTime->getTimestamp() + $dwellSeconds,
                    'dwell' => $dwellSeconds,
                ];
            }

            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 14 + $i,
                poiSamples: 18,
                travelKm: 180.0,
                timezoneOffset: 0,
                hasAirport: $i === 0 || $i === 2,
                spotCount: 2,
                spotDwellSeconds: 7200 + ($i * 1800),
                maxSpeedKmh: 240.0 - ($i * 10.0),
                avgSpeedKmh: 180.0 - ($i * 5.0),
                hasHighSpeedTransit: false,
                cohortPresenceRatio: $ratio,
                cohortMembers: $cohortMembers,
                staypoints: $staypoints,
            );
        }

        $dayKeys = array_keys($days);
        $draft   = $calculator->buildDraft($dayKeys, $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params = $draft->getParams();
        self::assertSame('vacation.extended', $params['storyline']);
        self::assertSame('vacation', $params['classification']);
        self::assertSame(3, $params['away_days']);
        self::assertSame(3, $params['raw_away_days']);
        self::assertSame(0, $params['bridged_away_days']);
        self::assertArrayHasKey('score_components', $params);
        $components = $params['score_components'];
        self::assertEqualsWithDelta(0.6, $components['away_days_norm'], 0.0001);
        self::assertGreaterThan(0.5, $components['quality']);
        self::assertGreaterThan(0.5, $components['max_distance_norm']);
        self::assertEqualsWithDelta(0.667, $components['people'], 0.001);
        self::assertEqualsWithDelta(0.5, $components['poi_diversity'], 0.0001);
        self::assertEqualsWithDelta(0.6, $params['away_days_norm'], 0.0001);
        self::assertEqualsWithDelta(0.667, $params['people_ratio'], 0.001);
        self::assertEqualsWithDelta(0.5, $params['poi_diversity'], 0.0001);
        self::assertSame(0.0, $params['transit_penalty']);
        self::assertSame(0.0, $params['transit_penalty_score']);
        self::assertTrue($params['airport_transfer']);
        self::assertFalse($params['high_speed_transit']);
        self::assertSame(0, $params['transit_days']);
        self::assertGreaterThan(0.0, $params['max_speed_kmh']);
        self::assertGreaterThan(0.0, $params['avg_speed_kmh']);
        self::assertArrayHasKey('primaryStaypoint', $params);
        self::assertArrayHasKey('primaryStaypointCity', $params);
        self::assertSame('Lisbon', $params['primaryStaypointCity']);
        self::assertSame('Lisbon', $params['place_city']);
        self::assertSame('Lisbon District', $params['primaryStaypointRegion']);
        self::assertSame('Portugal', $params['primaryStaypointCountry']);
        self::assertSame(
            ['Lisbon', 'Lisbon District', 'Portugal'],
            $params['primaryStaypointLocationParts'],
        );
        self::assertSame('Lisbon, Lisbon District, Portugal', $params['primaryStaypointLocation']);
        self::assertArrayHasKey('countries', $params);
        self::assertSame(['pt'], $params['countries']);
        self::assertGreaterThanOrEqual(7.0, $params['score']);

        self::assertArrayHasKey('vacation_title', $params);
        self::assertArrayHasKey('vacation_subtitle', $params);
        self::assertStringContainsString('Lisbon', (string) $params['vacation_title']);
        self::assertStringContainsString('Personenanteil:', (string) $params['vacation_subtitle']);

        self::assertArrayHasKey('member_selection', $params);
        $memberSelection = $params['member_selection'];
        $expectedMinimumFloor = (int) \ceil($params['min_items_per_day'] * $params['away_days'] * 0.6);
        self::assertSame($expectedMinimumFloor, $params['minimum_member_floor']);
        self::assertSame($params['raw_member_count'], $memberSelection['counts']['raw']);
        self::assertSame($params['minimum_member_floor'], $memberSelection['counts']['minimum_floor']);
        self::assertGreaterThanOrEqual($memberSelection['counts']['raw'], $memberSelection['counts']['curated']);
        self::assertSame(
            $memberSelection['counts']['raw'] - $memberSelection['counts']['curated'],
            $memberSelection['counts']['dropped'],
        );
        self::assertSame(4, $params['min_items_per_day']);
        self::assertSame(0, $memberSelection['near_duplicates']['blocked']);
        self::assertSame(0, $memberSelection['near_duplicates']['replacements']);
        self::assertSame(
            VacationTestMemberSelector::class,
            $memberSelection['options']['selector'],
        );
        self::assertSame($selectionOptions->targetTotal, $memberSelection['options']['target_total']);
        self::assertSame($selectionOptions->maxPerDay, $memberSelection['options']['max_per_day']);
        self::assertGreaterThan(0.0, $memberSelection['spacing']['average_seconds']);
        self::assertArrayNotHasKey('telemetry', $memberSelection);
        self::assertSame('vacation_weekend_transit', $memberSelection['selection_profile']);
        self::assertSame('vacation_weekend_transit', $memberSelection['decision']['resolved']);
        self::assertSame('vacation_weekend_transit', $memberSelection['decision']['base']);
        self::assertArrayHasKey('context', $memberSelection['decision']);

        $quality = $params['member_quality'] ?? [];
        self::assertIsArray($quality);
        self::assertIsArray($quality['ordered']);
        $qualitySummary = $quality['summary'] ?? [];
        self::assertIsArray($qualitySummary);
        foreach ($dayKeys as $dayKey) {
            self::assertArrayHasKey($dayKey, $qualitySummary['selection_per_day_distribution']);
            self::assertLessThanOrEqual(
                $selectionOptions->maxPerDay,
                $qualitySummary['selection_per_day_distribution'][$dayKey],
            );
        }
        self::assertSame($memberSelection['counts']['raw'], $qualitySummary['selection_counts']['raw']);
        self::assertSame($memberSelection['counts']['curated'], $qualitySummary['selection_counts']['curated']);
        self::assertSame($memberSelection['counts']['minimum_floor'], $qualitySummary['selection_counts']['minimum_floor']);
        self::assertSame('vacation_weekend_transit', $qualitySummary['selection_profile']);
        self::assertSame(
            'vacation_weekend_transit',
            $qualitySummary['selection_profile_decision']['resolved'],
        );
        self::assertSame(3, $params['spot_cluster_days']);
        self::assertSame(3, $params['total_days']);
        self::assertGreaterThan(0.0, $params['spot_exploration_bonus']);
        self::assertSame(1.0, $params['cohort_bonus']);
        self::assertEqualsWithDelta(0.4, $params['cohort_presence_ratio'], 0.0001);
        self::assertSame([
            101 => 3,
            202 => 4,
        ], $params['cohort_members']);

        $staypoint = $params['primaryStaypoint'];
        self::assertIsArray($staypoint);
        self::assertArrayHasKey('lat', $staypoint);
        self::assertArrayHasKey('lon', $staypoint);
        self::assertArrayHasKey('start', $staypoint);
        self::assertArrayHasKey('end', $staypoint);
        self::assertArrayHasKey('dwell_seconds', $staypoint);
        self::assertSame(18000, $staypoint['dwell_seconds']);
    }

    #[Test]
    public function scorePenalisesHighTransitShare(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $referenceDate  = new DateTimeImmutable('2024-04-15 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(targetTotal: 30, maxPerDay: 6),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            referenceDate: $referenceDate,
            minimumMemberFloor: 0,
            enforceDynamicMinimum: false,
        );

        $lisbonLocation = (new Location(
            provider: 'test',
            providerPlaceId: 'lisbon',
            displayName: 'Lisboa, Portugal',
            lat: 38.7223,
            lon: -9.1393,
            cell: 'cell-lisbon',
        ))
            ->setCity('Lisbon')
            ->setState('Lisbon District')
            ->setCountry('Portugal')
            ->setCountryCode('PT');

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $start    = new DateTimeImmutable('2024-04-01 09:00:00');
        $days     = [];
        $poiTypes = ['museum', 'park', 'aquarium'];
        for ($i = 0; $i < 3; ++$i) {
            $dayDate     = $start->add(new DateInterval('P' . $i . 'D'));
            $dayLocation = clone $lisbonLocation;
            $dayLocation->setType($poiTypes[$i % count($poiTypes)])->setCategory('tourism');
            $members = $this->makeMembersForDay($i, $dayDate, 3, $dayLocation);
            $dayKey  = $dayDate->format('Y-m-d');

            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 12 + $i,
                poiSamples: 16,
                travelKm: 160.0,
                timezoneOffset: 0,
                hasAirport: $i === 0,
                spotCount: 2,
                spotDwellSeconds: 5400,
                maxSpeedKmh: 210.0,
                avgSpeedKmh: 180.0,
                hasHighSpeedTransit: false,
                cohortPresenceRatio: 0.3,
                cohortMembers: [101 => 2],
            );
        }

        $dayKeys        = array_keys($days);
        $baselineDraft  = $calculator->buildDraft($dayKeys, $days, $home);
        self::assertInstanceOf(ClusterDraft::class, $baselineDraft);
        $baselineParams = $baselineDraft->getParams();
        self::assertSame('vacation.extended', $baselineParams['storyline']);

        $transitDays = $days;
        foreach ($transitDays as $index => &$summary) {
            if ($index === $dayKeys[1]) {
                $summary['hasHighSpeedTransit'] = true;
                $summary['avgSpeedKmh']         = 225.0;
                $summary['maxSpeedKmh']         = 260.0;
            }
        }
        unset($summary);

        $transitDraft  = $calculator->buildDraft($dayKeys, $transitDays, $home);
        self::assertInstanceOf(ClusterDraft::class, $transitDraft);
        $transitParams = $transitDraft->getParams();
        self::assertSame('vacation.short_trip', $transitParams['storyline']);

        self::assertSame(0.0, $baselineParams['transit_penalty']);
        self::assertSame(0.0, $baselineParams['transit_penalty_score']);
        self::assertGreaterThan(0.0, $transitParams['transit_penalty']);
        self::assertGreaterThan(0.0, $transitParams['transit_penalty_score']);
        self::assertTrue($transitParams['transit_ratio'] > 0.3);
        self::assertSame('vacation', $baselineParams['classification']);
        self::assertSame('short_trip', $transitParams['classification']);
        self::assertLessThan($baselineParams['score'], $transitParams['score']);
    }

    #[Test]
    public function bridgingLowActivityDaysCountsTowardsClassification(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $referenceDate  = new DateTimeImmutable('2024-05-01 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(targetTotal: 18, maxPerDay: 6),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            minAwayDays: 3,
            referenceDate: $referenceDate,
            minimumMemberFloor: 0,
            enforceDynamicMinimum: false,
        );

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $lisbonLocation = (new Location(
            provider: 'test',
            providerPlaceId: 'lisbon',
            displayName: 'Lisboa, Portugal',
            lat: 38.7223,
            lon: -9.1393,
            cell: 'cell-lisbon',
        ))
            ->setCity('Lisbon')
            ->setState('Lisbon District')
            ->setCountry('Portugal')
            ->setCountryCode('PT')
            ->setType('museum')
            ->setCategory('tourism');

        $start = new DateTimeImmutable('2024-04-10 09:00:00');

        $day0Members = $this->makeMembersForDay(0, $start, 3, $lisbonLocation);
        $day1Members = $this->makeMembersForDay(1, $start->add(new DateInterval('P1D')), 1, $lisbonLocation);
        $day2Members = $this->makeMembersForDay(2, $start->add(new DateInterval('P2D')), 3, $lisbonLocation);

        $dayKeys = [
            $start->format('Y-m-d'),
            $start->add(new DateInterval('P1D'))->format('Y-m-d'),
            $start->add(new DateInterval('P2D'))->format('Y-m-d'),
        ];

        $days = [
            $dayKeys[0] => $this->makeDaySummary(
                date: $dayKeys[0],
                weekday: (int) $start->format('N'),
                members: $day0Members,
                gpsMembers: $day0Members,
                baseAway: true,
                tourismHits: 10,
                poiSamples: 14,
                travelKm: 150.0,
                timezoneOffset: 0,
                hasAirport: true,
                spotCount: 2,
                spotDwellSeconds: 5400,
                cohortPresenceRatio: 0.35,
                cohortMembers: [101 => 2],
            ),
            $dayKeys[1] => $this->makeDaySummary(
                date: $dayKeys[1],
                weekday: (int) $start->add(new DateInterval('P1D'))->format('N'),
                members: $day1Members,
                gpsMembers: $day1Members,
                baseAway: false,
                tourismHits: 3,
                poiSamples: 5,
                travelKm: 40.0,
                timezoneOffset: 0,
                hasAirport: false,
                spotCount: 1,
                spotDwellSeconds: 1800,
                cohortPresenceRatio: 0.2,
                cohortMembers: [],
            ),
            $dayKeys[2] => $this->makeDaySummary(
                date: $dayKeys[2],
                weekday: (int) $start->add(new DateInterval('P2D'))->format('N'),
                members: $day2Members,
                gpsMembers: $day2Members,
                baseAway: true,
                tourismHits: 11,
                poiSamples: 15,
                travelKm: 170.0,
                timezoneOffset: 0,
                hasAirport: false,
                spotCount: 2,
                spotDwellSeconds: 5400,
                cohortPresenceRatio: 0.4,
                cohortMembers: [202 => 3],
            ),
        ];

        $draft = $calculator->buildDraft($dayKeys, $days, $home);
        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params = $draft->getParams();
        self::assertSame('vacation.short_trip', $params['storyline']);

        self::assertSame('short_trip', $params['classification']);
        self::assertSame(2, $params['raw_away_days']);
        self::assertSame(1, $params['bridged_away_days']);
        self::assertSame(3, $params['away_days']);
        self::assertEqualsWithDelta(0.6, $params['away_days_norm'], 0.0001);
        self::assertGreaterThanOrEqual(6.0, $params['score']);
        self::assertSame(2, $params['nights']);
    }

    #[Test]
    public function timeRangeUsesRunWindowIncludingStaypointBridge(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $referenceDate  = new DateTimeImmutable('2024-08-15 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            minAwayDays: 2,
            referenceDate: $referenceDate,
            minimumMemberFloor: 0,
            enforceDynamicMinimum: false,
        );

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $day1Date    = new DateTimeImmutable('2024-08-10 09:00:00', new DateTimeZone('Europe/Berlin'));
        $day1Members = $this->makeMembersForDay(0, $day1Date, 3);
        $day1Key     = $day1Date->format('Y-m-d');

        $bridgeDate  = new DateTimeImmutable('2024-08-11 00:00:00', new DateTimeZone('Europe/Berlin'));
        $bridgeKey   = $bridgeDate->format('Y-m-d');
        $bridgeStart = new DateTimeImmutable('2024-08-11 06:30:00', new DateTimeZone('Europe/Berlin'));
        $bridgeEnd   = new DateTimeImmutable('2024-08-11 22:15:00', new DateTimeZone('Europe/Berlin'));

        $day1Summary = $this->makeDaySummary(
            date: $day1Key,
            weekday: (int) $day1Date->format('N'),
            members: $day1Members,
            gpsMembers: $day1Members,
            baseAway: true,
            tourismHits: 10,
            poiSamples: 12,
            travelKm: 160.0,
            timezoneOffset: 120,
            hasAirport: false,
            spotCount: 2,
            spotDwellSeconds: 5400,
        );

        $bridgeSeed = $this->makeDaySummary(
            date: $bridgeKey,
            weekday: (int) $bridgeDate->format('N'),
            members: $day1Members,
            gpsMembers: $day1Members,
            baseAway: false,
            tourismHits: 0,
            poiSamples: 0,
            travelKm: 12.0,
            timezoneOffset: 120,
            hasAirport: false,
            spotCount: 0,
            spotDwellSeconds: 0,
        );
        $bridgeSeed['members']            = [];
        $bridgeSeed['gpsMembers']         = [];
        $bridgeSeed['photoCount']         = 0;
        $bridgeSeed['sufficientSamples']  = false;
        $bridgeSeed['staypoints']         = [[
            'lat'   => 38.7223,
            'lon'   => -9.1393,
            'start' => $bridgeStart->getTimestamp(),
            'end'   => $bridgeEnd->getTimestamp(),
            'dwell' => $bridgeEnd->getTimestamp() - $bridgeStart->getTimestamp(),
        ]];
        $bridgeSeed['staypointIndex']     = StaypointIndex::empty();
        $bridgeSeed['staypointCounts']    = ['2024-08-11:bridge' => 4];
        $bridgeSeed['dominantStaypoints'] = [[
            'key'          => 'bridge',
            'lat'          => 38.7223,
            'lon'          => -9.1393,
            'start'        => $bridgeStart->getTimestamp(),
            'end'          => $bridgeEnd->getTimestamp(),
            'dwellSeconds' => $bridgeEnd->getTimestamp() - $bridgeStart->getTimestamp(),
            'memberCount'  => 0,
        ]];
        $bridgeSeed['baseAway']           = false;
        $bridgeSeed['awayByDistance']     = false;
        $bridgeSeed['timezoneOffsets']    = [120 => 0];
        $bridgeSeed['countryCodes']       = ['pt' => true];
        $bridgeSeed['firstGpsMedia']      = null;
        $bridgeSeed['lastGpsMedia']       = null;

        $days = [
            $day1Key   => $day1Summary,
            $bridgeKey => $bridgeSeed,
        ];

        $draft = $calculator->buildDraft([$day1Key, $bridgeKey], $days, $home);
        self::assertInstanceOf(ClusterDraft::class, $draft);

        $params = $draft->getParams();

        $expectedFrom = $day1Members[0]->getTakenAt()?->getTimestamp();
        $expectedTo   = $bridgeEnd->getTimestamp();

        self::assertSame($expectedFrom, $params['time_range']['from']);
        self::assertSame($expectedTo, $params['time_range']['to']);
        self::assertSame(1, $params['bridged_away_days']);
        self::assertSame(2, $params['away_days']);
    }

    #[Test]
    public function buildDraftRequiresConfiguredMinimumAwayDays(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $referenceDate  = new DateTimeImmutable('2024-05-15 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            minAwayDays: 2,
            referenceDate: $referenceDate,
            minimumMemberFloor: 0,
            enforceDynamicMinimum: false,
        );

        $lisbonLocation = (new Location(
            provider: 'test',
            providerPlaceId: 'lisbon',
            displayName: 'Lisboa, Portugal',
            lat: 38.7223,
            lon: -9.1393,
            cell: 'cell-lisbon',
        ))
            ->setCity('Lisbon')
            ->setState('Lisbon District')
            ->setCountry('Portugal')
            ->setCountryCode('PT');

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $dayDate = new DateTimeImmutable('2024-05-01 09:00:00');
        $members = $this->makeMembersForDay(0, $dayDate, 4, $lisbonLocation);
        $dayKey  = $dayDate->format('Y-m-d');

        $days = [
            $dayKey => $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 12,
                poiSamples: 18,
                travelKm: 180.0,
                timezoneOffset: 0,
                hasAirport: true,
                spotCount: 2,
                spotDwellSeconds: 5400,
            ),
        ];

        self::assertNull($calculator->buildDraft([$dayKey], $days, $home));
    }

    #[Test]
    public function buildDraftRejectsTwoDayRunsWithDefaultMinimum(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $referenceDate  = new DateTimeImmutable('2024-05-10 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(targetTotal: 24, maxPerDay: 6),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            referenceDate: $referenceDate,
            minimumMemberFloor: 0,
            enforceDynamicMinimum: false,
        );

        $lisbonLocation = (new Location(
            provider: 'test',
            providerPlaceId: 'lisbon',
            displayName: 'Lisboa, Portugal',
            lat: 38.7223,
            lon: -9.1393,
            cell: 'cell-lisbon',
        ))
            ->setCity('Lisbon')
            ->setState('Lisbon District')
            ->setCountry('Portugal')
            ->setCountryCode('PT');

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $start   = new DateTimeImmutable('2024-05-06 09:00:00');
        $dayKeys = [];
        $days    = [];

        for ($i = 0; $i < 2; ++$i) {
            $dayDate   = $start->add(new DateInterval('P' . $i . 'D'));
            $members   = $this->makeMembersForDay($i, $dayDate, 4, $lisbonLocation);
            $dayKey    = $dayDate->format('Y-m-d');
            $dayKeys[] = $dayKey;

            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 12 + $i,
                poiSamples: 18,
                travelKm: 180.0,
                timezoneOffset: 0,
                hasAirport: $i === 0,
                spotCount: 2,
                spotDwellSeconds: 5400,
            );
        }

        self::assertNull($calculator->buildDraft($dayKeys, $days, $home));
    }

    #[Test]
    public function weekendExceptionAllowsTripsBelowMinimumAwayDays(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $referenceDate  = new DateTimeImmutable('2024-07-01 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(targetTotal: 24, maxPerDay: 6),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            minAwayDays: 3,
            referenceDate: $referenceDate,
            minimumMemberFloor: 0,
            enforceDynamicMinimum: false,
        );

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 120,
        ];

        $getawayLocation = (new Location(
            provider: 'test',
            providerPlaceId: 'warnemuende',
            displayName: 'Warnemünde, Germany',
            lat: 54.1760,
            lon: 12.0837,
            cell: 'cell-warnemuende',
        ))
            ->setCity('Warnemünde')
            ->setState('Mecklenburg-Vorpommern')
            ->setCountry('Germany')
            ->setCountryCode('DE')
            ->setCategory('tourism')
            ->setType('beach');

        $start   = new DateTimeImmutable('2024-07-06 09:00:00');
        $dayKeys = [];
        $days    = [];

        for ($i = 0; $i < 2; ++$i) {
            $dayDate = $start->add(new DateInterval('P' . $i . 'D'));
            $members = $this->makeMembersForDay($i, $dayDate, 4, $getawayLocation);
            $dayKey  = $dayDate->format('Y-m-d');

            $stayDuration = 10800 + ($i * 1800);
            $firstMember  = $members[0];
            $startStamp   = $firstMember->getTakenAt()?->getTimestamp() ?? $dayDate->getTimestamp();
            $staypoints   = [[
                'lat'   => (float) ($firstMember->getGpsLat() ?? $getawayLocation->getLat()),
                'lon'   => (float) ($firstMember->getGpsLon() ?? $getawayLocation->getLon()),
                'start' => $startStamp,
                'end'   => $startStamp + $stayDuration,
                'dwell' => $stayDuration,
            ]];

            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 9,
                poiSamples: 12,
                travelKm: 160.0,
                timezoneOffset: 120,
                hasAirport: false,
                spotCount: 2,
                spotDwellSeconds: 5400,
                maxSpeedKmh: 95.0,
                avgSpeedKmh: 68.0,
                hasHighSpeedTransit: false,
                cohortPresenceRatio: 0.4,
                cohortMembers: [101 => 2],
                staypoints: $staypoints,
                countryCodes: ['de' => true],
            );

            $dayKeys[] = $dayKey;
        }

        $draft = $calculator->buildDraft($dayKeys, $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params = $draft->getParams();

        self::assertSame('weekend_getaway', $params['classification']);
        self::assertTrue($params['weekend_getaway']);
        self::assertTrue($params['weekend_exception_applied']);
        self::assertSame('vacation.weekend', $params['storyline']);
        self::assertSame('vacation_weekend_getaway', $params['selection_profile']);
        self::assertSame(2, $params['away_days']);
        self::assertSame(1, $params['nights']);
        self::assertGreaterThan(0.0, $params['score']);
        self::assertSame(0, $params['weekend_gap_days']);

        $selection = $params['member_selection'];
        self::assertSame('vacation_weekend_getaway', $selection['selection_profile']);
        self::assertSame('vacation_weekend_getaway', $selection['decision']['resolved']);
        self::assertSame('vacation_weekend_transit', $selection['decision']['base']);
        self::assertSame(
            [
                'away_days'       => 2,
                'nights'          => 1,
                'weekend_getaway' => true,
                'gap_days'        => 0,
            ],
            $selection['decision']['context'],
        );
    }

    #[Test]
    public function weekendExceptionRespectsGapTolerance(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $referenceDate  = new DateTimeImmutable('2024-07-01 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(targetTotal: 24, maxPerDay: 6),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            minAwayDays: 5,
            referenceDate: $referenceDate,
            minimumMemberFloor: 0,
            enforceDynamicMinimum: false,
        );

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 120,
        ];

        $start = new DateTimeImmutable('2024-07-05 09:00:00');

        $dayKeys = [];
        $days    = [];

        // Home day before the weekend (bridged)
        $bridgeBeforeMembers = $this->makeMembersForDay(10, $start, 1);
        $bridgeBeforeKey     = $start->format('Y-m-d');
        $bridgeBefore        = $this->makeDaySummary(
            date: $bridgeBeforeKey,
            weekday: (int) $start->format('N'),
            members: $bridgeBeforeMembers,
            gpsMembers: $bridgeBeforeMembers,
            baseAway: false,
            tourismHits: 0,
            poiSamples: 0,
            travelKm: 0.0,
            timezoneOffset: 120,
            hasAirport: false,
            spotCount: 0,
            spotDwellSeconds: 0,
            maxSpeedKmh: 10.0,
            avgSpeedKmh: 5.0,
            hasHighSpeedTransit: false,
            cohortPresenceRatio: 0.0,
            cohortMembers: [],
            staypoints: [],
            countryCodes: ['de' => true],
        );
        $bridgeBefore['members']            = [];
        $bridgeBefore['gpsMembers']         = [];
        $bridgeBefore['sufficientSamples']  = false;
        $bridgeBefore['dominantStaypoints'] = [];
        $bridgeBefore['staypoints']         = [];
        $bridgeBefore['baseAway']           = false;
        $bridgeBefore['awayByDistance']     = false;
        $bridgeBefore['photoCount']         = 1;
        $bridgeBefore['firstGpsMedia']      = null;
        $bridgeBefore['lastGpsMedia']       = null;

        $dayKeys[]                = $bridgeBeforeKey;
        $days[$bridgeBeforeKey]   = $bridgeBefore;

        for ($i = 1; $i <= 2; ++$i) {
            $dayDate = $start->add(new DateInterval('P' . $i . 'D'));
            $members = $this->makeMembersForDay($i + 10, $dayDate, 4);
            $dayKey  = $dayDate->format('Y-m-d');

            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 8,
                poiSamples: 10,
                travelKm: 150.0,
                timezoneOffset: 120,
                hasAirport: false,
                spotCount: 2,
                spotDwellSeconds: 5400,
            );

            $dayKeys[] = $dayKey;
        }

        // Home day after the weekend (bridged)
        $bridgeAfterDate    = $start->add(new DateInterval('P3D'));
        $bridgeAfterMembers = $this->makeMembersForDay(13, $bridgeAfterDate, 1);
        $bridgeAfterKey     = $bridgeAfterDate->format('Y-m-d');
        $bridgeAfter        = $this->makeDaySummary(
            date: $bridgeAfterKey,
            weekday: (int) $bridgeAfterDate->format('N'),
            members: $bridgeAfterMembers,
            gpsMembers: $bridgeAfterMembers,
            baseAway: false,
            tourismHits: 0,
            poiSamples: 0,
            travelKm: 0.0,
            timezoneOffset: 120,
            hasAirport: false,
            spotCount: 0,
            spotDwellSeconds: 0,
            maxSpeedKmh: 10.0,
            avgSpeedKmh: 5.0,
            hasHighSpeedTransit: false,
            cohortPresenceRatio: 0.0,
            cohortMembers: [],
            staypoints: [],
            countryCodes: ['de' => true],
        );
        $bridgeAfter['members']            = [];
        $bridgeAfter['gpsMembers']         = [];
        $bridgeAfter['sufficientSamples']  = false;
        $bridgeAfter['dominantStaypoints'] = [];
        $bridgeAfter['staypoints']         = [];
        $bridgeAfter['baseAway']           = false;
        $bridgeAfter['awayByDistance']     = false;
        $bridgeAfter['photoCount']         = 1;
        $bridgeAfter['firstGpsMedia']      = null;
        $bridgeAfter['lastGpsMedia']       = null;

        $dayKeys[]                = $bridgeAfterKey;
        $days[$bridgeAfterKey]    = $bridgeAfter;

        $draft = $calculator->buildDraft($dayKeys, $days, $home);

        self::assertNull($draft);
    }

    #[Test]
    public function weekendExceptionDoesNotOverrideMissingCoreRequirement(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $emitter        = new RecordingMonitoringEmitter();
        $referenceDate  = new DateTimeImmutable('2024-07-01 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(targetTotal: 24, maxPerDay: 6),
            emitter: $emitter,
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            minAwayDays: 3,
            referenceDate: $referenceDate,
        );

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 120,
        ];

        $getawayLocation = (new Location(
            provider: 'test',
            providerPlaceId: 'warnemuende',
            displayName: 'Warnemünde, Germany',
            lat: 54.1760,
            lon: 12.0837,
            cell: 'cell-warnemuende',
        ))
            ->setCity('Warnemünde')
            ->setState('Mecklenburg-Vorpommern')
            ->setCountry('Germany')
            ->setCountryCode('DE')
            ->setCategory('tourism')
            ->setType('beach');

        $start      = new DateTimeImmutable('2024-07-06 09:00:00');
        $dayKeys    = [];
        $days       = [];
        $dayContext = [];

        for ($i = 0; $i < 2; ++$i) {
            $dayDate = $start->add(new DateInterval('P' . $i . 'D'));
            $members = $this->makeMembersForDay($i, $dayDate, 4, $getawayLocation);
            $dayKey  = $dayDate->format('Y-m-d');

            $stayDuration = 10800 + ($i * 1800);
            $firstMember  = $members[0];
            $startStamp   = $firstMember->getTakenAt()?->getTimestamp() ?? $dayDate->getTimestamp();
            $staypoints   = [[
                'lat'   => (float) ($firstMember->getGpsLat() ?? $getawayLocation->getLat()),
                'lon'   => (float) ($firstMember->getGpsLon() ?? $getawayLocation->getLon()),
                'start' => $startStamp,
                'end'   => $startStamp + $stayDuration,
                'dwell' => $stayDuration,
            ]];

            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 9,
                poiSamples: 12,
                travelKm: 160.0,
                timezoneOffset: 120,
                hasAirport: false,
                spotCount: 2,
                spotDwellSeconds: 5400,
                maxSpeedKmh: 95.0,
                avgSpeedKmh: 68.0,
                hasHighSpeedTransit: false,
                cohortPresenceRatio: 0.4,
                cohortMembers: [101 => 2],
                staypoints: $staypoints,
                countryCodes: ['de' => true],
            );

            $dayContext[$dayKey] = [
                'score'    => 0.35,
                'category' => 'peripheral',
                'duration' => null,
                'metrics'  => [],
            ];

            $dayKeys[] = $dayKey;
        }

        $draft = $calculator->buildDraft($dayKeys, $days, $home, $dayContext);

        self::assertNull($draft);

        self::assertCount(2, $emitter->events);

        $startEvent = $emitter->events[0];
        self::assertSame('vacation_curation', $startEvent['job']);
        self::assertSame('selection_start', $startEvent['status']);
        self::assertTrue($startEvent['context']['weekend_getaway']);
        self::assertTrue($startEvent['context']['weekend_exception']);
        self::assertSame(
            'vacation_weekend_getaway',
            $startEvent['context']['selection_profile_decision']['resolved'],
        );

        $completeEvent = $emitter->events[1];
        self::assertSame('vacation_curation', $completeEvent['job']);
        self::assertSame('selection_completed', $completeEvent['status']);
        self::assertSame('missing_core_days', $completeEvent['context']['reason']);
    }

    #[Test]
    public function longRunsUseLongRunSelectionProfile(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $emitter        = new RecordingMonitoringEmitter();
        $referenceDate  = new DateTimeImmutable('2024-06-01 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(targetTotal: 42, maxPerDay: 6),
            emitter: $emitter,
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            referenceDate: $referenceDate,
        );

        $lisbonLocation = (new Location(
            provider: 'test',
            providerPlaceId: 'lisbon',
            displayName: 'Lisboa, Portugal',
            lat: 38.7223,
            lon: -9.1393,
            cell: 'cell-lisbon',
        ))
            ->setCity('Lisbon')
            ->setState('Lisbon District')
            ->setCountry('Portugal')
            ->setCountryCode('PT');

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $start   = new DateTimeImmutable('2024-06-10 09:00:00');
        $dayKeys = [];
        $days    = [];

        for ($i = 0; $i < 8; ++$i) {
            $dayDate  = $start->add(new DateInterval('P' . $i . 'D'));
            $members  = $this->makeMembersForDay($i, $dayDate, 5, $lisbonLocation);
            $dayKey   = $dayDate->format('Y-m-d');
            $stayTime = 14400 + ($i * 900);
            $first    = $members[0];
            $startTs  = $first->getTakenAt()?->getTimestamp() ?? $dayDate->getTimestamp();
            $staypoints = [[
                'lat'   => (float) ($first->getGpsLat() ?? $lisbonLocation->getLat()),
                'lon'   => (float) ($first->getGpsLon() ?? $lisbonLocation->getLon()),
                'start' => $startTs,
                'end'   => $startTs + $stayTime,
                'dwell' => $stayTime,
            ]];

            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 14,
                poiSamples: 20,
                travelKm: 210.0,
                timezoneOffset: 60,
                hasAirport: $i === 0 || $i === 7,
                spotCount: 3,
                spotDwellSeconds: 7200,
                maxSpeedKmh: 95.0,
                avgSpeedKmh: 72.0,
                hasHighSpeedTransit: false,
                cohortPresenceRatio: 0.45,
                cohortMembers: [101 => 5],
                staypoints: $staypoints,
                countryCodes: ['pt' => true],
            );

            $dayKeys[] = $dayKey;
        }

        $draft = $calculator->buildDraft($dayKeys, $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params     = $draft->getParams();
        $selection  = $params['member_selection'];
        $decision   = $selection['decision'];

        self::assertSame(8, $params['away_days']);
        self::assertSame('vacation_long_run', $params['selection_profile']);
        self::assertSame('vacation_long_run', $selection['selection_profile']);
        self::assertSame('vacation_long_run', $decision['resolved']);
        self::assertSame('vacation_weekend_transit', $decision['base']);
        self::assertSame(
            'vacation_long_run',
            $params['member_quality']['summary']['selection_profile_decision']['resolved'],
        );

        self::assertNotEmpty($emitter->events);
        $startEvent = $emitter->events[0];
        self::assertSame('vacation_curation', $startEvent['job']);
        self::assertSame('selection_start', $startEvent['status']);
        self::assertSame(
            'vacation_long_run',
            $startEvent['context']['selection_profile_decision']['resolved'],
        );
    }

    #[Test]
    public function buildDraftRequiresConfiguredMinimumMembers(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $referenceDate  = new DateTimeImmutable('2024-06-15 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            minAwayDays: 1,
            minMembers: 10,
            referenceDate: $referenceDate,
        );

        $lisbonLocation = (new Location(
            provider: 'test',
            providerPlaceId: 'lisbon',
            displayName: 'Lisboa, Portugal',
            lat: 38.7223,
            lon: -9.1393,
            cell: 'cell-lisbon',
        ))
            ->setCity('Lisbon')
            ->setState('Lisbon District')
            ->setCountry('Portugal')
            ->setCountryCode('PT');

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $dayDate = new DateTimeImmutable('2024-06-10 09:00:00');
        $members = $this->makeMembersForDay(1, $dayDate, 3, $lisbonLocation);
        $dayKey  = $dayDate->format('Y-m-d');

        $days = [
            $dayKey => $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 10,
                poiSamples: 12,
                travelKm: 120.0,
                timezoneOffset: 0,
                hasAirport: false,
                spotCount: 1,
                spotDwellSeconds: 3600,
            ),
        ];

        self::assertNull($calculator->buildDraft([$dayKey], $days, $home));
    }

    #[Test]
    public function buildDraftUsesStaypointCityAsFallbackWhenPlaceCityMissing(): void
    {
        $labelResolver = new class implements LocationLabelResolverInterface {
            public function localityKey(?Location $location): ?string
            {
                return null;
            }

            public function displayLabel(?Location $location): ?string
            {
                return null;
            }

            public function localityKeyForMedia(Media $media): ?string
            {
                return null;
            }

            public function labelForMedia(Media $media): ?string
            {
                return null;
            }

            public function majorityLabel(array $members): ?string
            {
                if (count($members) <= 2) {
                    return 'Staypoint City, Spain';
                }

                return null;
            }

            public function majorityLocationComponents(array $members): array
            {
                if (count($members) <= 2) {
                    return ['city' => 'Staypoint City', 'country' => 'Spain'];
                }

                return [];
            }

            public function sameLocality(Media $a, Media $b): bool
            {
                return false;
            }
        };

        $poiAnalyzer = new class implements PoiContextAnalyzerInterface {
            public function resolvePrimaryPoi(Location $location): ?array
            {
                return null;
            }

            public function bestLabelForLocation(Location $location): ?string
            {
                return null;
            }

            public function majorityPoiContext(array $members): ?array
            {
                return null;
            }
        };

        $locationHelper = new LocationHelper($labelResolver, $poiAnalyzer);
        $referenceDate  = new DateTimeImmutable('2024-05-20 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(targetTotal: 20, maxPerDay: 4),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 25.0,
            minAwayDays: 2,
            referenceDate: $referenceDate,
        );

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $start   = new DateTimeImmutable('2024-05-10 09:00:00');
        $days    = [];
        $dayKeys = [];
        for ($i = 0; $i < 2; ++$i) {
            $dayDate   = $start->add(new DateInterval('P' . $i . 'D'));
            $members   = $this->makeMembersForDay(10 + $i, $dayDate, 3);
            $dayKey    = $dayDate->format('Y-m-d');
            $dayKeys[] = $dayKey;

            $staypoints   = [];
            $firstMember  = $members[0];
            $startTime    = $firstMember->getTakenAt();
            $dwellSeconds = 10800 + ($i * 900);
            if ($startTime instanceof DateTimeImmutable) {
                $lat         = $firstMember->getGpsLat() ?? 41.3874;
                $lon         = $firstMember->getGpsLon() ?? 2.1686;
                $staypoints[] = [
                    'lat'   => (float) $lat,
                    'lon'   => (float) $lon,
                    'start' => $startTime->getTimestamp(),
                    'end'   => $startTime->getTimestamp() + $dwellSeconds,
                    'dwell' => $dwellSeconds,
                ];
            }

            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 12 + $i,
                poiSamples: 12,
                travelKm: 150.0,
                timezoneOffset: 0,
                hasAirport: $i === 1,
                spotCount: 2,
                spotDwellSeconds: 5400 + ($i * 300),
                maxSpeedKmh: 180.0,
                avgSpeedKmh: 120.0,
                hasHighSpeedTransit: false,
                cohortPresenceRatio: 0.35,
                cohortMembers: [101 => 2],
                staypoints: $staypoints,
                countryCodes: ['es' => true],
            );
        }

        $draft = $calculator->buildDraft($dayKeys, $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params = $draft->getParams();
        self::assertSame('vacation.extended', $params['storyline']);
        self::assertSame('vacation.extended', $params['storyline']);
        self::assertSame('vacation.extended', $params['storyline']);
        self::assertSame('Staypoint City', $params['primaryStaypointCity']);
        self::assertSame('Staypoint City', $params['place_city']);
        self::assertSame('Staypoint City, Spain', $params['place_location']);
        self::assertSame('Spain', $params['place_country']);
        self::assertSame('Spain', $params['primaryStaypointCountry']);
        self::assertSame('Staypoint City, Spain', $params['primaryStaypointLocation']);
        self::assertSame(['Staypoint City', 'Spain'], $params['primaryStaypointLocationParts']);
        self::assertArrayNotHasKey('primaryStaypointRegion', $params);
        self::assertArrayHasKey('countries', $params);
        self::assertSame(['es'], $params['countries']);
    }

    #[Test]
    public function buildDraftPreservesUppercaseLocationComponents(): void
    {
        $labelResolver = new class implements LocationLabelResolverInterface {
            public function localityKey(?Location $location): ?string
            {
                return null;
            }

            public function displayLabel(?Location $location): ?string
            {
                return null;
            }

            public function localityKeyForMedia(Media $media): ?string
            {
                return null;
            }

            public function labelForMedia(Media $media): ?string
            {
                return null;
            }

            public function majorityLabel(array $members): ?string
            {
                return 'NEW YORK';
            }

            public function majorityLocationComponents(array $members): array
            {
                return [
                    'city'    => 'NEW YORK',
                    'region'  => 'NY',
                    'country' => 'USA',
                ];
            }

            public function sameLocality(Media $a, Media $b): bool
            {
                return false;
            }
        };

        $poiAnalyzer = new class implements PoiContextAnalyzerInterface {
            public function resolvePrimaryPoi(Location $location): ?array
            {
                return null;
            }

            public function bestLabelForLocation(Location $location): ?string
            {
                return null;
            }

            public function majorityPoiContext(array $members): ?array
            {
                return null;
            }
        };

        $locationHelper = new LocationHelper($labelResolver, $poiAnalyzer);
        $referenceDate  = new DateTimeImmutable('2024-06-10 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(targetTotal: 16, maxPerDay: 4),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 25.0,
            minAwayDays: 2,
            referenceDate: $referenceDate,
        );

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $start   = new DateTimeImmutable('2024-06-01 09:00:00');
        $days    = [];
        $dayKeys = [];

        for ($i = 0; $i < 2; ++$i) {
            $dayDate   = $start->add(new DateInterval('P' . $i . 'D'));
            $members   = $this->makeMembersForDay(30 + $i, $dayDate, 3);
            $dayKey    = $dayDate->format('Y-m-d');
            $dayKeys[] = $dayKey;

            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 6 + $i,
                poiSamples: 8,
                travelKm: 110.0,
                timezoneOffset: 0,
                hasAirport: false,
                spotCount: 1,
                spotDwellSeconds: 3600,
                maxSpeedKmh: 95.0,
                avgSpeedKmh: 70.0,
                hasHighSpeedTransit: false,
                cohortPresenceRatio: 0.25,
                cohortMembers: [],
                staypoints: [],
                countryCodes: ['us' => true],
            );
        }

        $draft = $calculator->buildDraft($dayKeys, $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);

        $params = $draft->getParams();
        self::assertSame('vacation.short_trip', $params['storyline']);
        self::assertSame('NEW YORK', $params['place_city']);
        self::assertSame('NY', $params['place_region']);
        self::assertSame('USA', $params['place_country']);
        self::assertSame('NEW YORK, NY, USA', $params['place_location']);
    }

    #[Test]
    public function buildDraftBackfillsRegionIntoLocationWhenPrimaryStaypointProvidesIt(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $referenceDate  = new DateTimeImmutable('2024-07-20 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            minAwayDays: 2,
            referenceDate: $referenceDate,
        );

        $staypointLocation = (new Location(
            provider: 'test',
            providerPlaceId: 'fallback-city',
            displayName: 'Fallback City, Fallback Region, Fallback Country',
            lat: 48.2082,
            lon: 16.3738,
            cell: 'cell-fallback',
        ))
            ->setCity('Fallback City')
            ->setState('Fallback Region')
            ->setCountry('Fallback Country')
            ->setCountryCode('FC');

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $start   = new DateTimeImmutable('2024-07-12 08:30:00');
        $days    = [];
        $dayKeys = [];
        for ($i = 0; $i < 2; ++$i) {
            $dayDate   = $start->add(new DateInterval('P' . $i . 'D'));
            $members   = $this->makeMembersForDay(30 + $i, $dayDate, 3);
            $gpsMembers = [];
            foreach ($members as $index => $member) {
                $takenAt = $member->getTakenAt();
                $gpsMembers[] = $this->makeMediaFixture(
                    id: 9000 + ($i * 10) + $index,
                    filename: sprintf('staypoint-%d-%d.jpg', $i, $index),
                    takenAt: $takenAt instanceof DateTimeImmutable ? $takenAt : $dayDate,
                    location: $staypointLocation,
                    configure: static function (Media $media): void {
                        $media->setTimezoneOffsetMin(0);
                    },
                );
            }

            $staypoints   = [];
            $firstMember  = $members[0];
            $startTime    = $firstMember->getTakenAt();
            $dwellSeconds = 12600 + ($i * 600);
            if ($startTime instanceof DateTimeImmutable) {
                $lat = $firstMember->getGpsLat() ?? 48.2082;
                $lon = $firstMember->getGpsLon() ?? 16.3738;
                $staypoints[] = [
                    'lat'   => (float) $lat,
                    'lon'   => (float) $lon,
                    'start' => $startTime->getTimestamp(),
                    'end'   => $startTime->getTimestamp() + $dwellSeconds,
                    'dwell' => $dwellSeconds,
                ];
            }

            $dayKey    = $dayDate->format('Y-m-d');
            $dayKeys[] = $dayKey;

            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $gpsMembers,
                baseAway: true,
                tourismHits: 10 + $i,
                poiSamples: 12,
                travelKm: 140.0,
                timezoneOffset: 0,
                hasAirport: $i === 0,
                spotCount: 2,
                spotDwellSeconds: 5400 + ($i * 300),
                maxSpeedKmh: 160.0,
                avgSpeedKmh: 110.0,
                hasHighSpeedTransit: false,
                cohortPresenceRatio: 0.25,
                cohortMembers: [101 => 1],
                staypoints: $staypoints,
                countryCodes: ['fc' => true],
            );
        }

        $draft = $calculator->buildDraft($dayKeys, $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params = $draft->getParams();

        self::assertSame('Fallback City', $params['primaryStaypointCity']);
        self::assertSame('Fallback Region', $params['primaryStaypointRegion']);
        self::assertSame('Fallback Country', $params['primaryStaypointCountry']);
        self::assertSame('Fallback City, Fallback Region, Fallback Country', $params['primaryStaypointLocation']);
        self::assertSame(['Fallback City', 'Fallback Region', 'Fallback Country'], $params['primaryStaypointLocationParts']);

        self::assertSame('Fallback City', $params['place_city']);
        self::assertSame('Fallback Region', $params['place_region']);
        self::assertSame('Fallback Country', $params['place_country']);
        self::assertSame('Fallback City, Fallback Region, Fallback Country', $params['place_location']);
    }

    #[Test]
    public function buildDraftExposesCountriesListWhenVisitingMultipleCountries(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $referenceDate  = new DateTimeImmutable('2024-06-20 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            referenceDate: $referenceDate,
        );

        $lisbonLocation = (new Location(
            provider: 'test',
            providerPlaceId: 'lisbon-multi',
            displayName: 'Lisboa, Portugal',
            lat: 38.7223,
            lon: -9.1393,
            cell: 'cell-lisbon-multi',
        ))
            ->setCity('Lisbon')
            ->setState('Lisbon District')
            ->setCountry('Portugal')
            ->setCountryCode('PT');

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $start   = new DateTimeImmutable('2024-06-05 09:00:00');
        $days    = [];
        $dayKeys = [];
        for ($i = 0; $i < 3; ++$i) {
            $dayDate   = $start->add(new DateInterval('P' . $i . 'D'));
            $members   = $this->makeMembersForDay(20 + $i, $dayDate, 3, $lisbonLocation);
            $dayKey    = $dayDate->format('Y-m-d');
            $dayKeys[] = $dayKey;

            $staypoints   = [];
            $firstMember  = $members[0];
            $startTime    = $firstMember->getTakenAt();
            $dwellSeconds = 13200 + ($i * 900);
            if ($startTime instanceof DateTimeImmutable) {
                $lat         = $firstMember->getGpsLat() ?? 38.7223;
                $lon         = $firstMember->getGpsLon() ?? -9.1393;
                $staypoints[] = [
                    'lat'   => (float) $lat,
                    'lon'   => (float) $lon,
                    'start' => $startTime->getTimestamp(),
                    'end'   => $startTime->getTimestamp() + $dwellSeconds,
                    'dwell' => $dwellSeconds,
                ];
            }

            $countryCodes = $i === 2 ? ['pt' => true, 'es' => true] : ['pt' => true];

            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 16 + $i,
                poiSamples: 18,
                travelKm: $i === 0 ? 180.0 : 90.0,
                timezoneOffset: 0,
                hasAirport: $i === 0 || $i === 2,
                spotCount: 2,
                spotDwellSeconds: 7200 + ($i * 900),
                maxSpeedKmh: 210.0 - ($i * 5.0),
                avgSpeedKmh: 160.0 - ($i * 5.0),
                hasHighSpeedTransit: $i === 0,
                cohortPresenceRatio: 0.4,
                cohortMembers: [101 => 2, 202 => 1],
                staypoints: $staypoints,
                countryCodes: $countryCodes,
            );
        }

        $draft = $calculator->buildDraft($dayKeys, $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params = $draft->getParams();
        self::assertSame(['es', 'pt'], $params['countries']);
        self::assertSame('Lisbon', $params['place_city']);
        self::assertSame('Portugal', $params['place_country']);
        self::assertSame('Lisbon', $params['primaryStaypointCity']);
        self::assertSame('Portugal', $params['primaryStaypointCountry']);
    }

    #[Test]
    public function buildDraftEmitsSelectionTelemetryAndDropsNearDuplicates(): void
    {
        $locationHelper    = LocationHelper::createDefault();
        $emitter           = new RecordingMonitoringEmitter();
        $selectionOptions  = new VacationSelectionOptions(targetTotal: 3, maxPerDay: 3);
        $filter = static function (array $members): array {
            return [
                'members'   => [$members[0], $members[2]],
                'telemetry' => [
                    'near_duplicate_blocked'      => 1,
                    'near_duplicate_replacements' => 1,
                    'spacing_rejections'          => 2,
                ],
            ];
        };

        $referenceDate = new DateTimeImmutable('2024-09-10 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator = $this->createCalculator(
            locationHelper: $locationHelper,
            options: $selectionOptions,
            curationFilter: $filter,
            emitter: $emitter,
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            minAwayDays: 1,
            referenceDate: $referenceDate,
        );

        $dayDate = new DateTimeImmutable('2024-09-01 10:00:00');
        $members = $this->makeMembersForDay(99, $dayDate, 3);
        $dayKey  = $dayDate->format('Y-m-d');

        $days = [
            $dayKey => $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 4,
                poiSamples: 6,
                travelKm: 120.0,
                timezoneOffset: 0,
                hasAirport: false,
                spotCount: 1,
                spotDwellSeconds: 3600,
            ),
        ];

        $home = [
            'lat'             => 48.2082,
            'lon'             => 16.3738,
            'radius_km'       => 12.0,
            'country'         => 'at',
            'timezone_offset' => 60,
        ];

        $draft = $calculator->buildDraft([$dayKey], $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params          = $draft->getParams();
        $memberSelection = $params['member_selection'];
        self::assertSame('vacation.day_trip', $params['storyline']);
        self::assertSame('vacation.day_trip', $memberSelection['storyline']);

        $memberQuality = $params['member_quality'];
        $runMetrics    = $memberQuality['summary']['selection_run_metrics'];
        self::assertSame('vacation.day_trip', $runMetrics['storyline']);
        self::assertSame(1, $runMetrics['run_length_days']);
        self::assertSame(1, $runMetrics['run_length_effective_days']);
        self::assertSame(0, $runMetrics['run_length_nights']);
        self::assertSame(0, $runMetrics['core_day_count']);
        self::assertSame(1, $runMetrics['peripheral_day_count']);
        self::assertSame($params['raw_member_count'], $runMetrics['raw_member_count']);
        self::assertSame($params['minimum_member_floor'], $runMetrics['minimum_member_floor']);
        self::assertSame($params['min_items_per_day'], $runMetrics['min_items_per_day']);
        self::assertArrayHasKey('selection_average_spacing_seconds', $runMetrics);
        self::assertArrayHasKey('selection_dedupe_rate', $runMetrics);
        self::assertArrayHasKey('selection_relaxations_applied', $runMetrics);
        self::assertEqualsWithDelta(
            $memberSelection['spacing']['average_seconds'],
            $runMetrics['selection_average_spacing_seconds'],
            0.0001,
        );
        self::assertEqualsWithDelta(1 / 3, $runMetrics['selection_dedupe_rate'], 0.001);
        self::assertSame([], $runMetrics['selection_relaxations_applied']);
        self::assertSame(
            $selectionOptions->targetTotal,
            $runMetrics['selection_profile']['target_total'],
        );
        self::assertSame(
            $selectionOptions->maxPerDay,
            $runMetrics['selection_profile']['max_per_day'],
        );
        self::assertSame(1, $runMetrics['poi_coverage']['poi_day_count']);

        self::assertSame(3, $memberSelection['counts']['raw']);
        self::assertSame(2, $memberSelection['counts']['curated']);
        self::assertSame(1, $memberSelection['counts']['dropped']);
        self::assertSame(1, $memberSelection['near_duplicates']['blocked']);
        self::assertSame(1, $memberSelection['near_duplicates']['replacements']);
        self::assertSame([$dayKey => 2], $memberQuality['summary']['selection_per_day_distribution']);
        self::assertGreaterThan(0.0, $memberSelection['spacing']['average_seconds']);
        self::assertSame(
            VacationTestMemberSelector::class,
            $memberSelection['options']['selector'],
        );
        self::assertSame('vacation_weekend_transit', $memberSelection['selection_profile']);
        self::assertSame('vacation_weekend_transit', $memberSelection['decision']['resolved']);
        self::assertSame('vacation_weekend_transit', $memberSelection['decision']['base']);
        self::assertSame(
            'vacation_weekend_transit',
            $runMetrics['selection_profile_decision']['resolved'],
        );
        self::assertSame(
            'vacation_weekend_transit',
            $memberQuality['summary']['selection_profile_decision']['resolved'],
        );

        self::assertCount(3, $draft->getMembers());

        self::assertCount(3, $emitter->events);
        $startEvent = $emitter->events[0];
        self::assertSame('vacation_curation', $startEvent['job']);
        self::assertSame('selection_start', $startEvent['status']);
        self::assertSame(3, $startEvent['context']['pre_count']);
        self::assertSame(1, $startEvent['context']['day_count']);
        self::assertSame('vacation.day_trip', $startEvent['context']['storyline']);
        self::assertSame(
            $selectionOptions->targetTotal,
            $startEvent['context']['selection_target_total'],
        );
        self::assertSame(
            $selectionOptions->peopleBalanceWeight,
            $startEvent['context']['selection_people_balance_weight'],
        );
        self::assertSame(
            'vacation_weekend_transit',
            $startEvent['context']['selection_profile_decision']['resolved'],
        );

        $metricsEvent = $emitter->events[1];
        self::assertSame('cluster.vacation', $metricsEvent['job']);
        self::assertSame('run_metrics', $metricsEvent['status']);
        self::assertSame(1, $metricsEvent['context']['run_length_days']);
        self::assertSame(
            $selectionOptions->targetTotal,
            $metricsEvent['context']['selection_target_total'],
        );
        self::assertArrayHasKey('poi_day_ratio', $metricsEvent['context']);
        self::assertArrayHasKey('people_unique_count', $metricsEvent['context']);
        self::assertArrayHasKey('selection_average_spacing_seconds', $metricsEvent['context']);
        self::assertArrayHasKey('selection_dedupe_rate', $metricsEvent['context']);
        self::assertArrayHasKey('selection_relaxations_applied', $metricsEvent['context']);
        self::assertEqualsWithDelta(
            $runMetrics['selection_average_spacing_seconds'],
            $metricsEvent['context']['selection_average_spacing_seconds'],
            0.0001,
        );
        self::assertEqualsWithDelta(
            $runMetrics['selection_dedupe_rate'],
            $metricsEvent['context']['selection_dedupe_rate'],
            0.001,
        );
        self::assertSame([], $metricsEvent['context']['selection_relaxations_applied']);
        self::assertSame(
            'vacation_weekend_transit',
            $metricsEvent['context']['selection_profile_decision']['resolved'],
        );

        $completeEvent = $emitter->events[2];
        self::assertSame('vacation_curation', $completeEvent['job']);
        self::assertSame('selection_completed', $completeEvent['status']);
        self::assertSame(2, $completeEvent['context']['post_count']);
        self::assertSame(1, $completeEvent['context']['dropped_total']);
        self::assertSame(1, $completeEvent['context']['near_duplicates_removed']);
        self::assertSame(1, $completeEvent['context']['near_duplicates_replaced']);
        self::assertSame(2, $completeEvent['context']['spacing_rejections']);
        self::assertGreaterThanOrEqual(0.0, $completeEvent['context']['average_spacing_seconds']);
        self::assertSame('vacation.day_trip', $completeEvent['context']['storyline']);
        self::assertArrayNotHasKey('reason', $completeEvent['context']);
    }

    #[Test]
    public function runMetricsIncludePositivePhashAverageWhenDistancesProvided(): void
    {
        $locationHelper   = LocationHelper::createDefault();
        $emitter          = new RecordingMonitoringEmitter();
        $selectionOptions = new VacationSelectionOptions(targetTotal: 2, maxPerDay: 2);
        $referenceDate    = new DateTimeImmutable('2024-09-15 00:00:00', new DateTimeZone('Europe/Berlin'));

        $calculator = $this->createCalculator(
            locationHelper: $locationHelper,
            options: $selectionOptions,
            telemetryOverrides: [
                'metrics' => [
                    'phash_distances' => [8, 12, 16],
                ],
            ],
            emitter: $emitter,
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            minAwayDays: 1,
            referenceDate: $referenceDate,
        );

        $dayDate = new DateTimeImmutable('2024-09-05 10:00:00');
        $members = $this->makeMembersForDay(55, $dayDate, 3);
        $dayKey  = $dayDate->format('Y-m-d');

        $days = [
            $dayKey => $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 6,
                poiSamples: 8,
                travelKm: 80.0,
                timezoneOffset: 0,
                hasAirport: false,
                spotCount: 1,
                spotDwellSeconds: 3600,
            ),
        ];

        $home = [
            'lat'             => 48.2082,
            'lon'             => 16.3738,
            'radius_km'       => 12.0,
            'country'         => 'at',
            'timezone_offset' => 60,
        ];

        $draft = $calculator->buildDraft([$dayKey], $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params     = $draft->getParams();
        $selection  = $params['member_selection'];
        $runMetrics = $params['member_quality']['summary']['selection_run_metrics'];

        self::assertGreaterThan(0.0, $runMetrics['phash_distribution']['average']);

        self::assertCount(3, $emitter->events);
        $metricsEvent = $emitter->events[1];
        self::assertSame('cluster.vacation', $metricsEvent['job']);
        self::assertSame('run_metrics', $metricsEvent['status']);
        self::assertGreaterThan(0.0, $metricsEvent['context']['phash_avg_distance']);
        self::assertSame(3, $metricsEvent['context']['phash_sample_count']);
        self::assertSame($runMetrics['raw_member_count'], $metricsEvent['context']['raw_member_count']);
        self::assertSame($runMetrics['minimum_member_floor'], $metricsEvent['context']['minimum_member_floor']);
        self::assertSame($runMetrics['min_items_per_day'], $metricsEvent['context']['min_items_per_day']);
    }

    #[Test]
    public function enforceDynamicMinimumAppliesBaselineFloor(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: new VacationSelectionOptions(targetTotal: 48, maxPerDay: 8),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            referenceDate: new DateTimeImmutable('2024-09-01 00:00:00', new DateTimeZone('Europe/Berlin')),
            minimumMemberFloor: 0,
            enforceDynamicMinimum: true,
        );

        $home = [
            'lat'             => 52.5200,
            'lon'             => 13.4050,
            'radius_km'       => 12.0,
            'country'         => 'de',
            'timezone_offset' => 60,
        ];

        $start   = new DateTimeImmutable('2024-08-10 09:00:00');
        $dayKeys = [];
        $days    = [];

        for ($i = 0; $i < 2; ++$i) {
            $dayDate  = $start->add(new DateInterval('P' . $i . 'D'));
            $members  = $this->makeMembersForDay($i, $dayDate, 40);
            $dayKey   = $dayDate->format('Y-m-d');
            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 10,
                poiSamples: 12,
                travelKm: 180.0,
                timezoneOffset: 0,
                hasAirport: false,
                spotCount: 2,
                spotDwellSeconds: 7200,
            );

            $dayKeys[] = $dayKey;
        }

        $draft = $calculator->buildDraft($dayKeys, $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params = $draft->getParams();

        self::assertSame(2, $params['away_days']);
        self::assertGreaterThanOrEqual(60, $params['minimum_member_floor']);
    }

    #[Test]
    public function buildDraftInterleavesMembersAcrossDays(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $selectionOptions = new VacationSelectionOptions(targetTotal: 24, maxPerDay: 6);
        $referenceDate    = new DateTimeImmutable('2024-08-20 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: $selectionOptions,
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
            referenceDate: $referenceDate,
        );

        $home = [
            'lat'             => 48.2082,
            'lon'             => 16.3738,
            'radius_km'       => 12.0,
            'country'         => 'at',
            'timezone_offset' => 60,
        ];

        $start   = new DateTimeImmutable('2024-08-10 08:00:00');
        $days    = [];
        $dayKeys = [];
        /** @var array<int, Media> $mediaIndex */
        $mediaIndex = [];
        $dayCount   = 4;
        for ($i = 0; $i < $dayCount; ++$i) {
            $dayDate   = $start->add(new DateInterval('P' . $i . 'D'));
            $members   = $this->makeMembersForDay($i, $dayDate, 7);
            $dayKey    = $dayDate->format('Y-m-d');
            $dayKeys[] = $dayKey;
            foreach ($members as $member) {
                $mediaIndex[$member->getId()] = $member;
            }
            $days[$dayKey] = $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 12 + $i,
                poiSamples: 16,
                travelKm: $i === 0 ? 180.0 : 90.0,
                timezoneOffset: 60,
                hasAirport: $i === 0 || $i === $dayCount - 1,
                spotCount: 3,
                spotDwellSeconds: 5400 + ($i * 600),
                maxSpeedKmh: 210.0 - ($i * 15.0),
                avgSpeedKmh: 160.0 - ($i * 7.5),
                hasHighSpeedTransit: $i === 0,
            );
        }

        $draft = $calculator->buildDraft($dayKeys, $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params    = $draft->getParams();
        $memberIds = $draft->getMembers();
        self::assertSame('vacation.extended', $params['storyline']);

        self::assertCount($dayCount * 7, $memberIds);

        $expectedOrder = [];
        $perDayCounts  = [];
        foreach ($dayKeys as $dayKey) {
            foreach ($days[$dayKey]['members'] as $media) {
                $perDayCounts[$dayKey] = ($perDayCounts[$dayKey] ?? 0) + 1;
                if ($perDayCounts[$dayKey] > $selectionOptions->maxPerDay) {
                    continue;
                }

                $expectedOrder[] = $media->getId();
                if (count($expectedOrder) >= $selectionOptions->targetTotal) {
                    break 2;
                }
            }
        }

        $memberQuality = $params['member_quality'];
        self::assertSame($expectedOrder, $memberQuality['ordered']);

        $selection = $params['member_selection'];
        self::assertSame('vacation.extended', $selection['storyline']);
        self::assertSame(28, $selection['counts']['raw']);
        self::assertSame($selectionOptions->targetTotal, $selection['counts']['curated']);
        self::assertSame(4, $selection['counts']['dropped']);
        self::assertArrayHasKey('minimum_floor', $selection['counts']);
        self::assertArrayNotHasKey('telemetry', $selection);

        $summary   = $memberQuality['summary'];
        $telemetry = $summary['selection_telemetry'];
        self::assertSame('vacation.extended', $telemetry['storyline']);
        self::assertArrayHasKey('relaxation_hints', $telemetry);
        self::assertArrayHasKey('selection_per_bucket_distribution', $summary);

        $expectedDistribution = [];
        $remaining            = $selectionOptions->targetTotal;
        foreach ($dayKeys as $dayKey) {
            $limit = min($selectionOptions->maxPerDay, count($days[$dayKey]['members']));
            if ($limit > $remaining) {
                $limit = $remaining;
            }

            $expectedDistribution[$dayKey] = $limit;
            $remaining -= $limit;
        }

        self::assertSame($expectedDistribution, $summary['selection_per_day_distribution']);
    }

    #[Test]
    public function insufficientMembersEmitsTelemetryAndAborts(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $emitter        = new RecordingMonitoringEmitter();
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            emitter: $emitter,
            minimumMemberFloor: 60,
        );

        $dayDate = new DateTimeImmutable('2024-05-01 09:00:00');
        $members = $this->makeMembersForDay(0, $dayDate, 5);
        $dayKey  = $dayDate->format('Y-m-d');

        $days = [
            $dayKey => $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 4,
                poiSamples: 6,
                travelKm: 120.0,
                timezoneOffset: 0,
                hasAirport: false,
                spotCount: 1,
                spotDwellSeconds: 3600,
            ),
        ];

        $home = [
            'lat'             => 48.2082,
            'lon'             => 16.3738,
            'radius_km'       => 12.0,
            'country'         => 'at',
            'timezone_offset' => 60,
        ];

        self::assertNull($calculator->buildDraft([$dayKey], $days, $home));

        self::assertCount(1, $emitter->events);
        $lastEvent = end($emitter->events);
        self::assertIsArray($lastEvent);
        self::assertSame('vacation_curation', $lastEvent['job']);
        self::assertSame('insufficient_members', $lastEvent['status']);
        self::assertSame(5, $lastEvent['context']['raw_member_count']);
        self::assertSame(60, $lastEvent['context']['minimum_member_floor']);
        self::assertSame(4, $lastEvent['context']['min_items_per_day']);
    }

    #[Test]
    public function adaptiveMemberFloorUsesGlobalMinimumForShortTrips(): void
    {
        $calculator = $this->createCalculator(
            locationHelper: LocationHelper::createDefault(),
            minimumMemberFloor: 60,
        );

        self::assertSame(60, $this->invokeMemberFloor($calculator, 2));
    }

    #[Test]
    public function adaptiveMemberFloorScalesWithLongRuns(): void
    {
        $calculator = $this->createCalculator(
            locationHelper: LocationHelper::createDefault(),
            minItemsPerDay: 5,
            minimumMemberFloor: 60,
        );

        self::assertSame(105, $this->invokeMemberFloor($calculator, 35));
    }

    /**
     * @return list<Media>
     */
    private function createCalculator(
        LocationHelper $locationHelper,
        ?VacationSelectionOptions $options = null,
        ?callable $curationFilter = null,
        array $telemetryOverrides = [],
        ?RecordingMonitoringEmitter $emitter = null,
        ?HolidayResolverInterface $holidayResolver = null,
        string $timezone = 'Europe/Berlin',
        float $movementThresholdKm = 35.0,
        int $minAwayDays = 3,
        int $minItemsPerDay = 4,
        int $minimumMemberFloor = 0,
        int $minMembers = 0,
        ?DateTimeImmutable $referenceDate = null,
        bool $enforceDynamicMinimum = false,
    ): VacationScoreCalculator {
        $defaultOptions    = $options ?? new VacationSelectionOptions();
        $selectionProfiles = new SelectionProfileProvider($defaultOptions, 'vacation');
        $routeSummarizer   = new RouteSummarizer();
        $dateFormatter     = new LocalizedDateFormatter();
        $storyTitleBuilder = new StoryTitleBuilder($routeSummarizer, $dateFormatter);

        return new VacationScoreCalculator(
            locationHelper: $locationHelper,
            memberSelector: new VacationTestMemberSelector($curationFilter, $telemetryOverrides),
            selectionProfiles: $selectionProfiles,
            storyTitleBuilder: $storyTitleBuilder,
            holidayResolver: $holidayResolver ?? new NullHolidayResolver(),
            timezone: $timezone,
            movementThresholdKm: $movementThresholdKm,
            minAwayDays: $minAwayDays,
            minItemsPerDay: $minItemsPerDay,
            minimumMemberFloor: $minimumMemberFloor,
            minMembers: $minMembers,
            monitoringEmitter: $emitter,
            referenceDate: $referenceDate,
            enforceDynamicMinimum: $enforceDynamicMinimum,
        );
    }

    private function invokeMemberFloor(VacationScoreCalculator $calculator, int $awayDays): int
    {
        $reflection = new ReflectionClass($calculator);
        $method     = $reflection->getMethod('resolveMinimumMemberFloor');
        $method->setAccessible(true);

        /** @var int $result */
        $result = $method->invoke($calculator, $awayDays);

        return $result;
    }

    /**
     * @return list<Media>
     */
    private function makeMembersForDay(int $index, DateTimeImmutable $base, int $count = 3, ?Location $location = null): array
    {
        $items  = [];
        $baseId = 100 + ($index * 100);
        for ($j = 0; $j < $count; ++$j) {
                $items[] = $this->makeMediaFixture(
                    id: $baseId + $j,
                    filename: sprintf('trip-day-%d-%d.jpg', $index, $j),
                    takenAt: $base->add(new DateInterval('PT' . ($j * 3) . 'H')),
                    lat: 38.7223 + ($index * 0.01) + ($j * 0.002),
                    lon: -9.1393 + ($index * 0.01) + ($j * 0.002),
                    configure: function (Media $media) use ($location): void {
                        $media->setTimezoneOffsetMin(0);
                        if ($location instanceof Location) {
                            $media->setLocation($location);
                        }
                        $media->setHasFaces(true);
                        if ($media->getId() % 3 === 0) {
                            $media->setPersons(['Alex']);
                        } else {
                            $media->setPersons(['Alex', 'Jamie']);
                        }
                    }
                );
            }

            return $items;
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
     * @param list<Media>                                   $members
     * @param list<Media>                                   $gpsMembers
     * @param array<int, int>                               $cohortMembers
     * @param list<array{lat:float,lon:float,start:int,end:int,dwell:int}> $staypoints
     * @param array<string, true>                           $countryCodes
     *
     * @return array<string, mixed>
     */
    private function makeDaySummary(
        string $date,
        int $weekday,
        array $members,
        array $gpsMembers,
        bool $baseAway,
        int $tourismHits,
        int $poiSamples,
        float $travelKm,
        int $timezoneOffset,
        bool $hasAirport,
        int $spotCount,
        int $spotDwellSeconds,
        float $maxSpeedKmh = 0.0,
        float $avgSpeedKmh = 0.0,
        bool $hasHighSpeedTransit = false,
        float $cohortPresenceRatio = 0.0,
        array $cohortMembers = [],
        array $staypoints = [],
        array $countryCodes = ['pt' => true],
    ): array {
        $first = $gpsMembers[0];
        $last  = $gpsMembers[count($gpsMembers) - 1];

        $index = StaypointIndex::build($date, $staypoints, $members);

        return [
            'date'                    => $date,
            'members'                 => $members,
            'gpsMembers'              => $gpsMembers,
            'maxDistanceKm'           => 180.0,
            'avgDistanceKm'           => 95.0,
            'travelKm'                => $travelKm,
            'maxSpeedKmh'             => $maxSpeedKmh,
            'avgSpeedKmh'             => $avgSpeedKmh,
            'hasHighSpeedTransit'     => $hasHighSpeedTransit,
            'countryCodes'            => $countryCodes,
            'timezoneOffsets'         => [$timezoneOffset => count($gpsMembers)],
            'localTimezoneIdentifier' => 'Europe/Lisbon',
            'localTimezoneOffset'     => $timezoneOffset,
            'tourismHits'             => $tourismHits,
            'poiSamples'              => $poiSamples,
            'tourismRatio'            => 0.6,
            'hasAirportPoi'           => $hasAirport,
            'weekday'                 => $weekday,
            'photoCount'              => count($members),
            'densityZ'                => 1.4,
            'isAwayCandidate'         => $baseAway,
            'sufficientSamples'       => true,
            'spotClusters'            => [$gpsMembers],
            'spotNoise'               => [],
            'spotCount'               => $spotCount,
            'spotNoiseSamples'        => 0,
            'spotDwellSeconds'        => $spotDwellSeconds,
            'staypoints'              => $staypoints,
            'staypointIndex'          => $index,
            'staypointCounts'         => $index->getCounts(),
            'dominantStaypoints'      => [],
            'transitRatio'            => 0.0,
            'poiDensity'              => 0.0,
            'cohortPresenceRatio'     => $cohortPresenceRatio,
            'cohortMembers'           => $cohortMembers,
            'baseLocation'            => null,
            'baseAway'                => $baseAway,
            'awayByDistance'          => true,
            'firstGpsMedia'           => $first,
            'lastGpsMedia'            => $last,
            'isSynthetic'             => false,
        ];
    }

    private function createPersistenceService(int $maxMembers = 20): ClusterPersistenceService
    {
        $lookup = new class implements MemberMediaLookupInterface {
            public function findByIds(array $ids, bool $onlyVideos = false): array
            {
                return [];
            }
        };

        return new ClusterPersistenceService(
            $this->createStub(EntityManagerInterface::class),
            $lookup,
            $this->createStub(CoverPickerInterface::class),
            250,
            $maxMembers,
        );
    }
}
