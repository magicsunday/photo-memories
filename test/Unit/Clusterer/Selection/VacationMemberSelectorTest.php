<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Selection;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Selection\SimilarityMetrics;
use MagicSunday\Memories\Clusterer\Selection\VacationMemberSelector;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use MagicSunday\Memories\Clusterer\Support\StaypointIndex;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\Quality\MediaQualityAggregator;
use PHPUnit\Framework\TestCase;
use function array_filter;
use function array_map;
use function array_replace;
use function count;
use function sha1;
use function substr;

/**
 * @covers \MagicSunday\Memories\Clusterer\Selection\VacationMemberSelector
 */
final class VacationMemberSelectorTest extends TestCase
{
    private VacationMemberSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new VacationMemberSelector(new MediaQualityAggregator(), new SimilarityMetrics());
    }

    public function testQualityFloorFiltersLowScoringItems(): void
    {
        $high = $this->createMedia('high.jpg', '2024-05-01T10:00:00+00:00', 0.8);
        $low  = $this->createMedia('low.jpg', '2024-05-01T11:00:00+00:00', 0.2);

        $summary = $this->createDaySummary('2024-05-01', [$high, $low]);

        $options = new VacationSelectionOptions(qualityFloor: 0.3, targetTotal: 5, maxPerDay: 5);
        $result  = $this->selector->select(['2024-05-01' => $summary], $this->createHome(), $options);

        self::assertCount(1, $result->getMembers());
        self::assertSame($high, $result->getMembers()[0]);

        $telemetry = $result->getTelemetry();
        self::assertSame(1, $telemetry['prefilter_quality_floor']);
    }

    public function testSlotSelectionPrefersHighestScoringPerSlot(): void
    {
        $morningPreferred = $this->createMedia('morning_best.jpg', '2024-05-02T09:00:00+00:00', 0.7);
        $morningOther     = $this->createMedia('morning_other.jpg', '2024-05-02T09:30:00+00:00', 0.6);
        $afternoon        = $this->createMedia('afternoon.jpg', '2024-05-02T15:00:00+00:00', 0.65);

        $summary = $this->createDaySummary('2024-05-02', [$morningPreferred, $morningOther, $afternoon]);

        $options = new VacationSelectionOptions(targetTotal: 2, maxPerDay: 2, timeSlotHours: 6, minSpacingSeconds: 0, qualityFloor: 0.1);
        $result  = $this->selector->select(['2024-05-02' => $summary], $this->createHome(), $options);

        $members = $result->getMembers();
        self::assertCount(2, $members);
        self::assertContains($morningPreferred, $members);
        self::assertContains($afternoon, $members);
        self::assertNotContains($morningOther, $members);
    }

    public function testSyntheticBurstSequencesCollapseToSingleCandidate(): void
    {
        $first  = $this->createMedia('synthetic-one.jpg', '2024-06-01T10:00:00+00:00', 0.6);
        $second = $this->createMedia('synthetic-two.jpg', '2024-06-01T10:00:20+00:00', 0.7);
        $third  = $this->createMedia('synthetic-three.jpg', '2024-06-01T10:00:35+00:00', 0.9);
        $other  = $this->createMedia('synthetic-late.jpg', '2024-06-01T11:15:00+00:00', 0.5);

        $summary = $this->createDaySummary('2024-06-01', [$first, $second, $third, $other]);

        $options = new VacationSelectionOptions(
            targetTotal: 4,
            maxPerDay: 4,
            timeSlotHours: 1,
            minSpacingSeconds: 0,
            qualityFloor: 0.1,
            minimumTotal: 1,
        );

        $result = $this->selector->select(['2024-06-01' => $summary], $this->createHome(), $options);

        $members = $result->getMembers();
        self::assertCount(2, $members);
        self::assertContains($third, $members);
        self::assertContains($other, $members);

        $telemetry = $result->getTelemetry();
        self::assertSame(2, $telemetry['burst_collapsed']);
    }

    public function testMinimumSpacingIsEnforced(): void
    {
        $first  = $this->createMedia('spacing-first.jpg', '2024-05-03T10:00:00+00:00', 0.75);
        $second = $this->createMedia('spacing-second.jpg', '2024-05-03T10:05:00+00:00', 0.6);
        $second->setCameraBodySerial('Device-B');

        $summary = $this->createDaySummary('2024-05-03', [$first, $second]);

        $options = new VacationSelectionOptions(targetTotal: 2, maxPerDay: 2, minSpacingSeconds: 900, qualityFloor: 0.1, minimumTotal: 1);
        $result  = $this->selector->select(['2024-05-03' => $summary], $this->createHome(), $options);

        $members = $result->getMembers();
        self::assertCount(1, $members);
        self::assertSame($first, $members[0]);
        self::assertGreaterThan(0, $result->getTelemetry()['spacing_rejections']);
    }

    public function testNearDuplicateReplacementPrefersHigherQuality(): void
    {
        $options = new VacationSelectionOptions(
            targetTotal: 2,
            maxPerDay: 2,
            minSpacingSeconds: 60,
            phashMinHamming: 2,
            qualityFloor: 0.1,
            selfiePenalty: 0.5,
        );

        $original = $this->createMedia('duplicate-original.jpg', '2024-05-04T12:00:00+00:00', 0.6);
        $original->setIsVideo(true);
        $original->setPhash64('abcdef1234567890');
        $replacement = $this->createMedia('duplicate-replacement.jpg', '2024-05-04T12:02:00+00:00', 0.9);
        $replacement->setPhash64('abcdef1234567890');
        $replacement->setPersons(['person']);

        $summary = $this->createDaySummary('2024-05-04', [$original, $replacement]);

        $result = $this->selector->select(['2024-05-04' => $summary], $this->createHome(), $options);

        $members = $result->getMembers();
        self::assertCount(1, $members);
        self::assertSame($replacement, $members[0]);
        self::assertSame(1, $result->getTelemetry()['near_duplicate_replacements']);
    }

    public function testStaypointQuotaLimitsSelections(): void
    {
        $first  = $this->createMedia('staypoint-one.jpg', '2024-05-05T08:00:00+00:00', 0.7);
        $second = $this->createMedia('staypoint-two.jpg', '2024-05-05T08:10:00+00:00', 0.75);
        $third  = $this->createMedia('staypoint-three.jpg', '2024-05-05T08:20:00+00:00', 0.65);

        $timestamp = $first->getTakenAt()?->getTimestamp();
        $staypoint = [
            'lat'   => 0.0,
            'lon'   => 0.0,
            'start' => $timestamp - 60,
            'end'   => $timestamp + 1800,
            'dwell' => 3600,
        ];

        $summary = $this->createDaySummary('2024-05-05', [$first, $second, $third], ['staypoints' => [$staypoint]]);

        $options = new VacationSelectionOptions(targetTotal: 3, maxPerDay: 3, maxPerStaypoint: 1, qualityFloor: 0.1, minSpacingSeconds: 0, minimumTotal: 1);
        $result  = $this->selector->select(['2024-05-05' => $summary], $this->createHome(), $options);

        self::assertCount(1, $result->getMembers());
        self::assertGreaterThan(0, $result->getTelemetry()['staypoint_rejections']);
    }

    public function testFaceAndVideoBonusesAdjustRanking(): void
    {
        $plain = $this->createMedia('bonus-plain.jpg', '2024-05-06T09:00:00+00:00', 0.7);
        $faces = $this->createMedia('bonus-face.jpg', '2024-05-06T09:10:00+00:00', 0.6);
        $faces->setPersons(['a', 'b']);
        $video = $this->createMedia('bonus-video.mp4', '2024-05-06T16:00:00+00:00', 0.55);
        $video->setIsVideo(true);

        $plain->setCameraBodySerial('Device-A');
        $faces->setCameraBodySerial('Device-B');
        $video->setCameraBodySerial('Device-C');

        $summary = $this->createDaySummary('2024-05-06', [$plain, $faces, $video]);

        $options = new VacationSelectionOptions(
            targetTotal: 2,
            maxPerDay: 2,
            videoBonus: 0.3,
            faceBonus: 0.4,
            selfiePenalty: 0.0,
            qualityFloor: 0.1,
            timeSlotHours: 6,
            minSpacingSeconds: 0,
        );

        $result  = $this->selector->select(['2024-05-06' => $summary], $this->createHome(), $options);
        $members = $result->getMembers();

        self::assertCount(2, $members);
        self::assertContains($faces, $members);
        self::assertContains($video, $members);
        self::assertNotContains($plain, $members);
    }

    public function testTelemetryReportsFaceDetectionAvailability(): void
    {
        $media = $this->createMedia('availability.jpg', '2024-05-07T10:00:00+00:00', 0.7);

        $summary = $this->createDaySummary('2024-05-07', [$media]);

        $options = new VacationSelectionOptions(
            targetTotal: 1,
            maxPerDay: 1,
            faceBonus: 0.12,
            videoBonus: 0.22,
            faceDetectionAvailable: false,
            qualityFloor: 0.1,
        );

        $result = $this->selector->select(['2024-05-07' => $summary], $this->createHome(), $options);

        $telemetry = $result->getTelemetry();

        self::assertFalse($telemetry['face_detection_available']);
        self::assertTrue($telemetry['face_detection_people_weight_adjusted']);
        self::assertSame(0.12, $telemetry['face_bonus']);
        self::assertSame(0.22, $telemetry['video_bonus']);
    }

    public function testPeopleBalanceEncouragesDiverseFaces(): void
    {
        $aliceFirst  = $this->createPersonMedia('alice-first.jpg', '2024-05-12T09:00:00+00:00', 0.95, 'person-a');
        $aliceSecond = $this->createPersonMedia('alice-second.jpg', '2024-05-12T10:00:00+00:00', 0.9, 'person-a');
        $aliceThird  = $this->createPersonMedia('alice-third.jpg', '2024-05-12T11:00:00+00:00', 0.85, 'person-a');
        $bob         = $this->createPersonMedia('bob.jpg', '2024-05-12T12:00:00+00:00', 0.8, 'person-b');
        $carol       = $this->createPersonMedia('carol.jpg', '2024-05-12T13:00:00+00:00', 0.75, 'person-c');

        $summary = $this->createDaySummary('2024-05-12', [$aliceFirst, $aliceSecond, $aliceThird, $bob, $carol]);

        $options = new VacationSelectionOptions(
            targetTotal: 4,
            maxPerDay: 5,
            minSpacingSeconds: 0,
            timeSlotHours: 1,
            qualityFloor: 0.1,
            enablePeopleBalance: true,
            peopleBalanceWeight: 0.65,
            repeatPenalty: 0.4,
            minimumTotal: 4,
        );

        $result = $this->selector->select(['2024-05-12' => $summary], $this->createHome(), $options);

        $members = $result->getMembers();
        self::assertCount(4, $members);
        self::assertContains($bob, $members);
        self::assertContains($carol, $members);
        self::assertNotContains($aliceThird, $members);

        $counts = $this->countPersons($members);
        self::assertSame(2, $counts['person-a'] ?? 0);
        self::assertSame(1, $counts['person-b'] ?? 0);
        self::assertSame(1, $counts['person-c'] ?? 0);

        $telemetry = $result->getTelemetry();
        self::assertTrue($telemetry['people_balance_enabled']);
        self::assertGreaterThan(0, $telemetry['people_balance_penalized']);
        self::assertSame(1, $telemetry['people_balance_rejected']);
        self::assertCount(3, $telemetry['people_balance_counts']);
        self::assertSame(2, $telemetry['people_balance_counts']['person-a'] ?? 0);
        self::assertSame(1, $telemetry['people_balance_counts']['person-b'] ?? 0);
        self::assertSame(1, $telemetry['people_balance_counts']['person-c'] ?? 0);
    }

    public function testPeopleBalanceDisabledKeepsTopScoringFaces(): void
    {
        $aliceFirst  = $this->createPersonMedia('alice-first-disabled.jpg', '2024-05-13T09:00:00+00:00', 0.95, 'person-a');
        $aliceSecond = $this->createPersonMedia('alice-second-disabled.jpg', '2024-05-13T10:00:00+00:00', 0.9, 'person-a');
        $aliceThird  = $this->createPersonMedia('alice-third-disabled.jpg', '2024-05-13T11:00:00+00:00', 0.88, 'person-a');
        $bob         = $this->createPersonMedia('bob-disabled.jpg', '2024-05-13T12:00:00+00:00', 0.82, 'person-b');
        $carol       = $this->createPersonMedia('carol-disabled.jpg', '2024-05-13T13:00:00+00:00', 0.78, 'person-c');

        $summary = $this->createDaySummary('2024-05-13', [$aliceFirst, $aliceSecond, $aliceThird, $bob, $carol]);

        $options = new VacationSelectionOptions(
            targetTotal: 4,
            maxPerDay: 5,
            minSpacingSeconds: 0,
            timeSlotHours: 1,
            qualityFloor: 0.1,
            enablePeopleBalance: false,
            peopleBalanceWeight: 0.65,
            repeatPenalty: 0.4,
            minimumTotal: 4,
        );

        $result = $this->selector->select(['2024-05-13' => $summary], $this->createHome(), $options);

        $members = $result->getMembers();
        self::assertCount(4, $members);
        self::assertContains($aliceThird, $members);
        self::assertNotContains($carol, $members);

        $counts = $this->countPersons($members);
        self::assertSame(3, $counts['person-a'] ?? 0);
        self::assertSame(1, $counts['person-b'] ?? 0);
        self::assertArrayNotHasKey('person-c', $counts);

        $telemetry = $result->getTelemetry();
        self::assertFalse($telemetry['people_balance_enabled']);
        self::assertSame(0, $telemetry['people_balance_rejected']);
    }

    public function testDiversifierCreatesBalancedOrderAcrossDays(): void
    {
        $dayOneFirst  = $this->createMedia('day1-first.jpg', '2024-05-07T08:00:00+00:00', 0.9);
        $dayOneSecond = $this->createMedia('day1-second.jpg', '2024-05-07T12:00:00+00:00', 0.85);
        $dayTwoFirst  = $this->createMedia('day2-first.jpg', '2024-05-08T09:00:00+00:00', 0.8);
        $dayTwoSecond = $this->createMedia('day2-second.jpg', '2024-05-08T13:00:00+00:00', 0.75);

        $summaryA = $this->createDaySummary('2024-05-07', [$dayOneFirst, $dayOneSecond]);
        $summaryB = $this->createDaySummary('2024-05-08', [$dayTwoFirst, $dayTwoSecond]);

        $options = new VacationSelectionOptions(targetTotal: 3, maxPerDay: 2, minSpacingSeconds: 0, qualityFloor: 0.1);
        $result  = $this->selector->select([
            '2024-05-07' => $summaryA,
            '2024-05-08' => $summaryB,
        ], $this->createHome(), $options);

        $members = $result->getMembers();
        self::assertCount(3, $members);
        $dates = array_map(static fn (Media $media): ?string => $media->getTakenAt()?->format('Y-m-d'), $members);
        $dayOneOccurrences = count(array_filter($dates, static fn (?string $day): bool => $day === '2024-05-07'));
        $dayTwoOccurrences = count(array_filter($dates, static fn (?string $day): bool => $day === '2024-05-08'));
        self::assertSame(2, $dayOneOccurrences);
        self::assertSame(1, $dayTwoOccurrences);
    }

    public function testDayContextAdjustsDailyCaps(): void
    {
        $coreMembers = [
            $this->createMedia('core-1.jpg', '2024-07-01T08:00:00+00:00', 0.95),
            $this->createMedia('core-2.jpg', '2024-07-01T09:00:00+00:00', 0.9),
            $this->createMedia('core-3.jpg', '2024-07-01T10:00:00+00:00', 0.88),
            $this->createMedia('core-4.jpg', '2024-07-01T11:00:00+00:00', 0.86),
        ];
        $peripheralMembers = [
            $this->createMedia('peripheral-1.jpg', '2024-07-02T08:00:00+00:00', 0.82),
            $this->createMedia('peripheral-2.jpg', '2024-07-02T09:00:00+00:00', 0.81),
            $this->createMedia('peripheral-3.jpg', '2024-07-02T10:00:00+00:00', 0.8),
            $this->createMedia('peripheral-4.jpg', '2024-07-02T11:00:00+00:00', 0.79),
        ];

        $coreSummary = $this->createDaySummary('2024-07-01', $coreMembers, [
            'selectionContext' => ['category' => 'core', 'score' => 0.9, 'duration' => null, 'metrics' => []],
        ]);
        $peripheralSummary = $this->createDaySummary('2024-07-02', $peripheralMembers, [
            'selectionContext' => ['category' => 'peripheral', 'score' => 0.5, 'duration' => null, 'metrics' => []],
        ]);

        $options = new VacationSelectionOptions(
            targetTotal: 6,
            maxPerDay: 4,
            maxPerStaypoint: 3,
            minSpacingSeconds: 0,
            qualityFloor: 0.1,
            minimumTotal: 6,
        );

        $result = $this->selector->select([
            '2024-07-01' => $coreSummary,
            '2024-07-02' => $peripheralSummary,
        ], $this->createHome(), $options);

        $members = $result->getMembers();
        self::assertCount(6, $members);

        $counts = $this->countMembersByDay($members);
        self::assertSame(4, $counts['2024-07-01'] ?? 0);
        self::assertSame(2, $counts['2024-07-02'] ?? 0);

        $telemetry  = $result->getTelemetry();
        $thresholds = $telemetry['thresholds'];
        self::assertSame(3, $thresholds['raw_per_day_cap']);
        self::assertSame(3, $thresholds['base_per_day_cap']);
        self::assertSame(['2024-07-01' => 4, '2024-07-02' => 2], $thresholds['day_caps']);
        self::assertSame(['2024-07-01' => 'core', '2024-07-02' => 'peripheral'], $thresholds['day_categories']);
        self::assertSame(['2024-07-01' => 0, '2024-07-02' => 0], $thresholds['day_spacing_seconds']);
        self::assertSame(1, $thresholds['max_per_staypoint']);
    }

    public function testDaySpacingDefaultsToProfileMinimum(): void
    {
        $first  = $this->createMedia('spacing-default-1.jpg', '2024-09-01T10:00:00+00:00', 0.9);
        $second = $this->createMedia('spacing-default-2.jpg', '2024-09-01T10:45:00+00:00', 0.85);

        $summary = $this->createDaySummary('2024-09-01', [$first, $second]);

        $options = new VacationSelectionOptions(
            targetTotal: 2,
            maxPerDay: 2,
            minSpacingSeconds: 1800,
            qualityFloor: 0.1,
            minimumTotal: 1,
        );

        $result    = $this->selector->select(['2024-09-01' => $summary], $this->createHome(), $options);
        $members   = $result->getMembers();
        $telemetry = $result->getTelemetry();

        self::assertCount(2, $members);
        self::assertSame(0, $telemetry['spacing_rejections']);

        $thresholds = $telemetry['thresholds'];
        self::assertSame(['2024-09-01' => 2], $thresholds['day_caps']);
        self::assertSame(['2024-09-01' => 1800], $thresholds['day_spacing_seconds']);
    }

    public function testAdaptiveDaySpacingUsesDuration(): void
    {
        $first  = $this->createMedia('spacing-adapt-1.jpg', '2024-08-15T09:00:00+00:00', 0.92);
        $second = $this->createMedia('spacing-adapt-2.jpg', '2024-08-15T10:00:00+00:00', 0.9);
        $third  = $this->createMedia('spacing-adapt-3.jpg', '2024-08-15T11:00:00+00:00', 0.88);

        $summary = $this->createDaySummary('2024-08-15', [$first, $second, $third], [
            'selectionContext' => ['category' => 'core', 'score' => 0.8, 'duration' => 43200, 'metrics' => []],
        ]);

        $options = new VacationSelectionOptions(
            targetTotal: 3,
            maxPerDay: 3,
            minSpacingSeconds: 600,
            qualityFloor: 0.1,
            minimumTotal: 1,
        );

        $result    = $this->selector->select(['2024-08-15' => $summary], $this->createHome(), $options);
        $members   = $result->getMembers();
        $telemetry = $result->getTelemetry();

        self::assertCount(1, $members);
        self::assertSame(2, $telemetry['spacing_rejections']);

        $thresholds = $telemetry['thresholds'];
        self::assertSame(['2024-08-15' => 3], $thresholds['day_caps']);
        self::assertSame(['2024-08-15' => 10800], $thresholds['day_spacing_seconds']);
    }

    public function testRelaxationStopsAfterMinSpacingAdjustment(): void
    {
        $first  = $this->createMedia('relax-first.jpg', '2024-05-10T10:00:00+00:00', 0.85);
        $second = $this->createMedia('relax-second.jpg', '2024-05-10T10:10:00+00:00', 0.8);
        $third  = $this->createMedia('relax-third.jpg', '2024-05-10T10:20:00+00:00', 0.78);

        $summary = $this->createDaySummary('2024-05-10', [$first, $second, $third]);

        $options = new VacationSelectionOptions(
            targetTotal: 3,
            maxPerDay: 3,
            minSpacingSeconds: 1200,
            qualityFloor: 0.1,
            minimumTotal: 2,
        );

        $result = $this->selector->select(['2024-05-10' => $summary], $this->createHome(), $options);

        $members   = $result->getMembers();
        $telemetry = $result->getTelemetry();

        self::assertCount(2, $members);
        self::assertSame(2, $telemetry['minimum_total']);
        self::assertTrue($telemetry['minimum_total_met']);
        self::assertCount(1, $telemetry['relaxations']);

        $relaxation = $telemetry['relaxations'][0];
        self::assertSame('min_spacing_seconds', $relaxation['rule']);
        self::assertSame(1200, $relaxation['from']);
        self::assertSame(0, $relaxation['to']);
        self::assertTrue($telemetry['thresholds']['spacing_relaxed_to_zero']);
        self::assertFalse($telemetry['thresholds']['phash_relaxed_to_zero']);
        self::assertSame(2, $telemetry['selected_total']);
    }

    public function testRelaxationEscalatesWhenMultipleConstraintsApply(): void
    {
        $first  = $this->createMedia('multi-first.jpg', '2024-05-11T08:00:00+00:00', 0.9);
        $second = $this->createMedia('multi-second.jpg', '2024-05-11T08:10:00+00:00', 0.85);
        $third  = $this->createMedia('multi-third.jpg', '2024-05-11T08:20:00+00:00', 0.83);

        $second->setPhash64('0000000000000000');
        $third->setPhash64('0000000000000001');

        $timestamp = $first->getTakenAt()?->getTimestamp();
        self::assertNotNull($timestamp);

        $staypoint = [
            'lat'   => 0.0,
            'lon'   => 0.0,
            'start' => $timestamp - 60,
            'end'   => $timestamp + 3600,
            'dwell' => 3600,
        ];

        $summary = $this->createDaySummary('2024-05-11', [$first, $second, $third], ['staypoints' => [$staypoint]]);

        $options = new VacationSelectionOptions(
            targetTotal: 3,
            maxPerDay: 1,
            minSpacingSeconds: 1800,
            phashMinHamming: 2,
            maxPerStaypoint: 1,
            qualityFloor: 0.1,
            minimumTotal: 2,
        );

        $result = $this->selector->select(['2024-05-11' => $summary], $this->createHome(), $options);

        $members   = $result->getMembers();
        $telemetry = $result->getTelemetry();

        self::assertCount(1, $members);
        self::assertFalse($telemetry['minimum_total_met']);
        self::assertSame(1, $telemetry['selected_total']);
        self::assertSame(2, $telemetry['staypoint_rejections']);

        $rules = array_map(static fn (array $entry): string => $entry['rule'], $telemetry['relaxations']);
        self::assertContains('min_spacing_seconds', $rules);
        self::assertContains('phash_min_hamming', $rules);
        self::assertContains('max_per_day', $rules);
        self::assertContains('max_per_staypoint', $rules);
        self::assertTrue($telemetry['thresholds']['spacing_relaxed_to_zero']);
        self::assertTrue($telemetry['thresholds']['phash_relaxed_to_zero']);
        self::assertSame(1, $telemetry['thresholds']['max_per_staypoint']);
    }

    public function testEffectivePhashThresholdElevatesDuplicateDetection(): void
    {
        $first = $this->createMedia('adaptive-phash-a.jpg', '2024-08-01T09:00:00+00:00', 0.92);
        $first->setPhash64('0000000000000000');

        $second = $this->createMedia('adaptive-phash-b.jpg', '2024-08-01T09:02:00+00:00', 0.85);
        $second->setPhash64('000000000000000f');

        $third = $this->createMedia('adaptive-phash-c.jpg', '2024-08-01T09:04:00+00:00', 0.88);
        $third->setPhash64('ffffffffffffffff');

        $summary = $this->createDaySummary('2024-08-01', [$first, $second, $third]);

        $options = new VacationSelectionOptions(
            targetTotal: 3,
            maxPerDay: 3,
            minSpacingSeconds: 0,
            phashMinHamming: 1,
            qualityFloor: 0.1,
            minimumTotal: 2,
        );

        $result = $this->selector->select(['2024-08-01' => $summary], $this->createHome(), $options);

        $members = $result->getMembers();
        self::assertCount(1, $members);
        self::assertContains($first, $members);

        $telemetry  = $result->getTelemetry();
        $thresholds = $telemetry['thresholds'];

        self::assertSame(2, $telemetry['near_duplicate_blocked']);
        self::assertGreaterThanOrEqual($options->phashMinHamming, $thresholds['phash_min_effective']);
        self::assertSame($options->phashPercentile, $thresholds['phash_percentile_ratio']);
        self::assertSame(4, $thresholds['phash_percentile_threshold']);
        self::assertGreaterThanOrEqual(1, $thresholds['phash_sample_count']);
    }

    public function testAdaptivePercentileRespectsCustomOverride(): void
    {
        $first = $this->createMedia('adaptive-custom-a.jpg', '2024-08-02T09:00:00+00:00', 0.92);
        $first->setPhash64('0000000000000000');

        $second = $this->createMedia('adaptive-custom-b.jpg', '2024-08-02T09:02:00+00:00', 0.85);
        $second->setPhash64('000000000000000f');

        $third = $this->createMedia('adaptive-custom-c.jpg', '2024-08-02T09:04:00+00:00', 0.88);
        $third->setPhash64('ffffffffffffffff');

        $summary = $this->createDaySummary('2024-08-02', [$first, $second, $third]);

        $options = new VacationSelectionOptions(
            targetTotal: 3,
            maxPerDay: 3,
            minSpacingSeconds: 0,
            phashMinHamming: 1,
            qualityFloor: 0.1,
            minimumTotal: 2,
            phashPercentile: 0.75,
        );

        $result = $this->selector->select(['2024-08-02' => $summary], $this->createHome(), $options);

        $members = $result->getMembers();
        self::assertCount(1, $members);
        self::assertContains($first, $members);

        $telemetry  = $result->getTelemetry();
        $thresholds = $telemetry['thresholds'];

        self::assertSame($options->phashPercentile, $thresholds['phash_percentile_ratio']);
        self::assertSame(60, $thresholds['phash_percentile_threshold']);
        self::assertGreaterThanOrEqual($options->phashMinHamming, $thresholds['phash_min_effective']);
    }

    /**
     * @param list<Media>              $members
     * @param array<string, mixed>     $overrides
     * @return array<string, mixed>
     */
    private function createDaySummary(string $date, array $members, array $overrides = []): array
    {
        $base = [
            'date'                    => $date,
            'members'                 => $members,
            'gpsMembers'              => $members,
            'maxDistanceKm'           => 0.0,
            'distanceSum'             => 0.0,
            'distanceCount'           => 0,
            'avgDistanceKm'           => 0.0,
            'travelKm'                => 0.0,
            'maxSpeedKmh'             => 0.0,
            'avgSpeedKmh'             => 0.0,
            'hasHighSpeedTransit'     => false,
            'countryCodes'            => [],
            'timezoneOffsets'         => [0 => 1],
            'localTimezoneIdentifier' => 'UTC',
            'localTimezoneOffset'     => 0,
            'tourismHits'             => 0,
            'poiSamples'              => 0,
            'tourismRatio'            => 0.0,
            'hasAirportPoi'           => false,
            'weekday'                 => 1,
            'photoCount'              => count($members),
            'densityZ'                => 0.0,
            'isAwayCandidate'         => false,
            'sufficientSamples'       => false,
            'spotClusters'            => [],
            'spotNoise'               => [],
            'spotCount'               => 0,
            'spotNoiseSamples'        => 0,
            'spotDwellSeconds'        => 0,
            'staypoints'              => [],
            'staypointIndex'          => StaypointIndex::empty(),
            'staypointCounts'         => [],
            'dominantStaypoints'      => [],
            'transitRatio'            => 0.0,
            'poiDensity'              => 0.0,
            'cohortPresenceRatio'     => 0.0,
            'cohortMembers'           => [],
            'baseLocation'            => null,
            'baseAway'                => false,
            'awayByDistance'          => false,
            'firstGpsMedia'           => $members[0] ?? null,
            'lastGpsMedia'            => $members[count($members) - 1] ?? null,
            'timezoneIdentifierVotes' => [],
            'isSynthetic'             => false,
            'selectionContext'        => ['category' => 'core', 'score' => 0.0, 'duration' => null, 'metrics' => []],
        ];

        $summary = array_replace($base, $overrides);

        if (($summary['staypoints'] ?? []) === []) {
            $staypoints = [];
            foreach ($summary['members'] as $member) {
                $takenAt = $member->getTakenAt();
                if ($takenAt instanceof DateTimeImmutable) {
                    $timestamp = $takenAt->getTimestamp();
                    $staypoints[] = [
                        'lat'   => 0.0,
                        'lon'   => 0.0,
                        'start' => $timestamp - 60,
                        'end'   => $timestamp + 60,
                        'dwell' => 120,
                    ];
                }
            }
            $summary['staypoints'] = $staypoints;
        }

        $summary['staypointIndex'] = StaypointIndex::build($date, $summary['staypoints'], $summary['members']);

        $summary['staypointCounts'] = $summary['staypointIndex']->getCounts();

        if (!isset($summary['dominantStaypoints'])) {
            $summary['dominantStaypoints'] = [];
        }

        if (!isset($summary['transitRatio'])) {
            $summary['transitRatio'] = 0.0;
        }

        if (!isset($summary['poiDensity'])) {
            $summary['poiDensity'] = 0.0;
        }

        return $summary;
    }

    /**
     * @param list<Media> $members
     *
     * @return array<string, int>
     */
    private function countMembersByDay(array $members): array
    {
        $counts = [];
        foreach ($members as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $day = $takenAt->format('Y-m-d');
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        return $counts;
    }

    private function createPersonMedia(string $filename, string $time, float $quality, string $person): Media
    {
        $media = $this->createMedia($filename, $time, $quality);
        $media->setPersons([$person]);

        return $media;
    }

    /**
     * @param list<Media> $members
     *
     * @return array<string, int>
     */
    private function countPersons(array $members): array
    {
        $counts = [];
        foreach ($members as $media) {
            $persons = $media->getPersons();
            if ($persons === null) {
                continue;
            }

            foreach ($persons as $person) {
                $counts[$person] = ($counts[$person] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function createHome(): array
    {
        return [
            'lat'            => 0.0,
            'lon'            => 0.0,
            'radius_km'      => 0.0,
            'country'        => null,
            'timezone_offset'=> 0,
        ];
    }

    private function createMedia(string $filename, string $time, float $quality): Media
    {
        $media = new Media('/tmp/' . $filename, sha1($filename), 1024);
        $media->setTakenAt(new DateTimeImmutable($time));
        $media->setQualityScore($quality);
        $media->setNoShow(false);
        $media->setLowQuality(false);
        $media->setPersons([]);
        $media->setHasFaces(false);
        $media->setFacesCount(0);
        $media->setIsVideo(false);
        $media->setCameraMake('TestMake');
        $media->setCameraModel('TestModel');
        $media->setCameraBodySerial('Device');
        $media->setPhash64(substr(sha1($filename), 0, 16));

        return $media;
    }
}
