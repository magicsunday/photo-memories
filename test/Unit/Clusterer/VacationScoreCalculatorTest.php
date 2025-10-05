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
use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Service\VacationScoreCalculator;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\ClusterPersistenceService;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Service\Feed\CoverPickerInterface;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\Contract\LocationLabelResolverInterface;
use MagicSunday\Memories\Utility\Contract\PoiContextAnalyzerInterface;
use MagicSunday\Memories\Utility\LocationHelper;
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
        $locationHelper = LocationHelper::createDefault();
        $calculator     = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            holidayResolver: new NullHolidayResolver(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
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

        $start = new DateTimeImmutable('2024-04-01 09:00:00');
        $days  = [];
        for ($i = 0; $i < 3; ++$i) {
            $dayDate       = $start->add(new DateInterval('P' . $i . 'D'));
            $members       = $this->makeMembersForDay($i, $dayDate, 3, $lisbonLocation);
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
                travelKm: 120.0,
                timezoneOffset: 0,
                hasAirport: $i === 0 || $i === 2,
                spotCount: 2,
                spotDwellSeconds: 7200 + ($i * 1800),
                maxSpeedKmh: 240.0 - ($i * 10.0),
                avgSpeedKmh: 180.0 - ($i * 5.0),
                hasHighSpeedTransit: true,
                cohortPresenceRatio: $ratio,
                cohortMembers: $cohortMembers,
                staypoints: $staypoints,
            );
        }

        $draft = $calculator->buildDraft(array_keys($days), $days, $home);

        self::assertInstanceOf(ClusterDraft::class, $draft);
        $params = $draft->getParams();
        self::assertSame('vacation', $params['classification']);
        self::assertSame(3, $params['away_days']);
        self::assertTrue($params['airport_transfer']);
        self::assertTrue($params['high_speed_transit']);
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
        self::assertGreaterThan(8.0, $params['score']);
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
        $calculator     = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            holidayResolver: new NullHolidayResolver(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 25.0,
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
            $members   = $this->makeMembersForDay(10 + $i, $dayDate, 2);
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
        $calculator     = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            holidayResolver: new NullHolidayResolver(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 25.0,
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
            $members   = $this->makeMembersForDay(30 + $i, $dayDate, 2);
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
        self::assertSame('NEW YORK', $params['place_city']);
        self::assertSame('NY', $params['place_region']);
        self::assertSame('USA', $params['place_country']);
        self::assertSame('NEW YORK, NY, USA', $params['place_location']);
    }

    #[Test]
    public function buildDraftBackfillsRegionIntoLocationWhenPrimaryStaypointProvidesIt(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $calculator     = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            holidayResolver: new NullHolidayResolver(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
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
            $members   = $this->makeMembersForDay(30 + $i, $dayDate, 2);
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
        $calculator     = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            holidayResolver: new NullHolidayResolver(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
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
                travelKm: 180.0,
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
    public function buildDraftInterleavesMembersAcrossDays(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $calculator     = new VacationScoreCalculator(
            locationHelper: $locationHelper,
            holidayResolver: new NullHolidayResolver(),
            timezone: 'Europe/Berlin',
            movementThresholdKm: 30.0,
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
                travelKm: 180.0,
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
        $memberIds = $draft->getMembers();
        self::assertGreaterThan(20, count($memberIds));

        $clamped = $this->clampMemberList($memberIds, 20);

        $represented = [];
        foreach ($clamped as $memberId) {
            $dayIndex               = intdiv($memberId - 100, 100);
            $represented[$dayIndex] = true;
        }

        $expectedDays = [];
        for ($i = 0; $i < $dayCount; ++$i) {
            $expectedDays[] = $i;
        }

        $actualDays = array_keys($represented);
        sort($actualDays);

        self::assertSame($expectedDays, $actualDays);

        $remaining = array_slice($clamped, count($dayKeys));
        self::assertNotSame([], $remaining);

        $reflection  = new ReflectionClass(VacationScoreCalculator::class);
        $scoreMethod = $reflection->getMethod('evaluateMediaScore');
        $scoreMethod->setAccessible(true);

        /** @var list<array{id:int,score:float,timestamp:int}> $scored */
        $scored = [];
        foreach ($remaining as $memberId) {
            $media   = $mediaIndex[$memberId];
            $takenAt = $media->getTakenAt();
            /** @var float $score */
            $score    = $scoreMethod->invoke($calculator, $media);
            $scored[] = [
                'id'        => $memberId,
                'score'     => $score,
                'timestamp' => $takenAt instanceof DateTimeImmutable ? $takenAt->getTimestamp() : 0,
            ];
        }

        $sorted = $scored;
        usort($sorted, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                if ($a['timestamp'] === $b['timestamp']) {
                    return $a['id'] <=> $b['id'];
                }

                return $a['timestamp'] <=> $b['timestamp'];
            }

            return $a['score'] < $b['score'] ? 1 : -1;
        });

        $expectedOrder = array_map(static fn (array $entry): int => $entry['id'], $sorted);

        self::assertSame($expectedOrder, $remaining);
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
