<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Service\VacationScoreCalculator;
use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use MagicSunday\Memories\Clusterer\Support\StaypointIndex;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Service\Clusterer\Title\LocalizedDateFormatter;
use MagicSunday\Memories\Service\Clusterer\Title\RouteSummarizer;
use MagicSunday\Memories\Service\Clusterer\Title\StoryTitleBuilder;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\RecordingMonitoringEmitter;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\VacationTestMemberSelector;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class VacationScoreCalculatorIntegrationTest extends TestCase
{
    #[Test]
    public function itSkipsRunsWithoutCoreDaysUnlessException(): void
    {
        $locationHelper = LocationHelper::createDefault();
        $emitter        = new RecordingMonitoringEmitter();
        $options        = new VacationSelectionOptions(targetTotal: 3, maxPerDay: 3);
        $referenceDate  = new DateTimeImmutable('2024-04-15 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator     = $this->createCalculator(
            locationHelper: $locationHelper,
            options: $options,
            emitter: $emitter,
            minAwayDays: 1,
            referenceDate: $referenceDate,
        );

        $home = [
            'lat'             => 48.2082,
            'lon'             => 16.3738,
            'radius_km'       => 12.0,
            'country'         => 'at',
            'timezone_offset' => 60,
        ];

        $dayDate = new DateTimeImmutable('2024-04-09 09:00:00'); // Tuesday
        $members = $this->makeMembersForDay(0, $dayDate, 3);
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
                travelKm: 120.0,
                timezoneOffset: 0,
                hasAirport: false,
                spotCount: 1,
                spotDwellSeconds: 3600,
            ),
        ];

        $dayContext = [$dayKey => ['category' => 'peripheral']];

        self::assertNull($calculator->buildDraft([$dayKey], $days, $home, $dayContext));
        self::assertCount(2, $emitter->events);

        $startEvent = $emitter->events[0];
        self::assertSame('vacation_curation', $startEvent['job']);
        self::assertSame('selection_start', $startEvent['status']);

        $completeEvent = $emitter->events[1];
        self::assertSame('vacation_curation', $completeEvent['job']);
        self::assertSame('selection_completed', $completeEvent['status']);
        self::assertSame('missing_core_days', $completeEvent['context']['reason']);
        self::assertArrayNotHasKey('selection_average_spacing_seconds', $completeEvent['context']);
    }

    #[Test]
    public function itEmitsRunMetricsWithSpacingDedupeAndRelaxations(): void
    {
        $locationHelper   = LocationHelper::createDefault();
        $emitter          = new RecordingMonitoringEmitter();
        $selectionOptions = new VacationSelectionOptions(targetTotal: 3, maxPerDay: 3);
        $filter = static function (array $members): array {
            return [
                'members'   => [$members[0], $members[2]],
                'telemetry' => [
                    'near_duplicate_blocked'      => 1,
                    'near_duplicate_replacements' => 0,
                    'spacing_rejections'          => 2,
                ],
            ];
        };

        $telemetryOverrides = [
            'relaxation_hints' => [
                'min_spacing_seconds reduzieren, um engere Serien zu erlauben.',
                'phash_min_hamming senken, damit ähnliche Motive nicht verworfen werden.',
            ],
        ];

        $referenceDate = new DateTimeImmutable('2024-04-20 00:00:00', new DateTimeZone('Europe/Berlin'));
        $calculator    = $this->createCalculator(
            locationHelper: $locationHelper,
            options: $selectionOptions,
            curationFilter: $filter,
            telemetryOverrides: $telemetryOverrides,
            emitter: $emitter,
            minAwayDays: 1,
            referenceDate: $referenceDate,
        );

        $dayDate = new DateTimeImmutable('2024-04-12 10:00:00');
        $members = $this->makeMembersForDay(10, $dayDate, 3);
        $dayKey  = $dayDate->format('Y-m-d');

        $days = [
            $dayKey => $this->makeDaySummary(
                date: $dayKey,
                weekday: (int) $dayDate->format('N'),
                members: $members,
                gpsMembers: $members,
                baseAway: true,
                tourismHits: 5,
                poiSamples: 6,
                travelKm: 140.0,
                timezoneOffset: 0,
                hasAirport: false,
                spotCount: 1,
                spotDwellSeconds: 4200,
            ),
        ];

        $home = [
            'lat'             => 48.2082,
            'lon'             => 16.3738,
            'radius_km'       => 12.0,
            'country'         => 'at',
            'timezone_offset' => 60,
        ];

        $dayContext = [$dayKey => ['category' => 'core']];

        $draft = $calculator->buildDraft([$dayKey], $days, $home, $dayContext);

        self::assertNotNull($draft);
        $params        = $draft->getParams();
        $memberMetrics = $params['member_selection'];
        $runMetrics    = $memberMetrics['run_metrics'];

        self::assertEqualsWithDelta(1 / 3, $runMetrics['selection_dedupe_rate'], 0.001);
        self::assertGreaterThan(0.0, $runMetrics['selection_average_spacing_seconds']);
        self::assertSame(
            $telemetryOverrides['relaxation_hints'],
            $runMetrics['selection_relaxations_applied'],
        );

        self::assertCount(3, $emitter->events);
        $metricsEvent = $emitter->events[1];
        self::assertSame('cluster.vacation', $metricsEvent['job']);
        self::assertSame('run_metrics', $metricsEvent['status']);
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
        self::assertSame(
            $telemetryOverrides['relaxation_hints'],
            $metricsEvent['context']['selection_relaxations_applied'],
        );
    }

    private function createCalculator(
        LocationHelper $locationHelper,
        ?VacationSelectionOptions $options = null,
        ?callable $curationFilter = null,
        array $telemetryOverrides = [],
        ?RecordingMonitoringEmitter $emitter = null,
        string $timezone = 'Europe/Berlin',
        float $movementThresholdKm = 35.0,
        int $minAwayDays = 2,
        int $minMembers = 0,
        ?DateTimeImmutable $referenceDate = null,
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
            holidayResolver: new NullHolidayResolver(),
            timezone: $timezone,
            movementThresholdKm: $movementThresholdKm,
            minAwayDays: $minAwayDays,
            minMembers: $minMembers,
            monitoringEmitter: $emitter,
            referenceDate: $referenceDate,
        );
    }

    /**
     * @param list<Media> $members
     * @param list<Media> $gpsMembers
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
    ): array {
        $index = StaypointIndex::build($date, [], $members);

        return [
            'date'                    => $date,
            'members'                 => $members,
            'gpsMembers'              => $gpsMembers,
            'maxDistanceKm'           => 120.0,
            'avgDistanceKm'           => 80.0,
            'travelKm'                => $travelKm,
            'maxSpeedKmh'             => 150.0,
            'avgSpeedKmh'             => 100.0,
            'hasHighSpeedTransit'     => false,
            'countryCodes'            => ['at' => true],
            'timezoneOffsets'         => [$timezoneOffset => count($gpsMembers)],
            'localTimezoneIdentifier' => 'Europe/Vienna',
            'localTimezoneOffset'     => $timezoneOffset,
            'tourismHits'             => $tourismHits,
            'poiSamples'              => $poiSamples,
            'tourismRatio'            => 0.5,
            'hasAirportPoi'           => $hasAirport,
            'weekday'                 => $weekday,
            'photoCount'              => count($members),
            'densityZ'                => 1.0,
            'isAwayCandidate'         => $baseAway,
            'sufficientSamples'       => true,
            'spotClusters'            => [$gpsMembers],
            'spotNoise'               => [],
            'spotCount'               => $spotCount,
            'spotNoiseSamples'        => 0,
            'spotDwellSeconds'        => $spotDwellSeconds,
            'staypoints'              => [],
            'staypointIndex'          => $index,
            'staypointCounts'         => $index->getCounts(),
            'dominantStaypoints'      => [],
            'transitRatio'            => 0.0,
            'poiDensity'              => 0.0,
            'cohortPresenceRatio'     => 0.2,
            'cohortMembers'           => [],
            'baseLocation'            => null,
            'baseAway'                => $baseAway,
            'awayByDistance'          => true,
            'firstGpsMedia'           => $gpsMembers[0] ?? null,
            'lastGpsMedia'            => $gpsMembers[count($gpsMembers) - 1] ?? null,
            'isSynthetic'             => false,
        ];
    }

    /**
     * @return list<Media>
     */
    private function makeMembersForDay(int $index, DateTimeImmutable $base, int $count = 3): array
    {
        $items  = [];
        $baseId = 100 + ($index * 100);
        for ($j = 0; $j < $count; ++$j) {
            $items[] = $this->makeMediaFixture(
                id: $baseId + $j,
                filename: sprintf('integration-day-%d-%d.jpg', $index, $j),
                takenAt: $base->add(new DateInterval('PT' . ($j * 3) . 'H')),
                lat: 48.2082 + ($index * 0.01) + ($j * 0.002),
                lon: 16.3738 + ($index * 0.01) + ($j * 0.002),
                configure: static function (Media $media): void {
                    $media->setTimezoneOffsetMin(0);
                    $media->setHasFaces(true);
                },
            );
        }

        return $items;
    }
}
