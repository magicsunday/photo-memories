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

        $options = new VacationSelectionOptions(targetTotal: 2, maxPerDay: 2, timeSlotHours: 6, qualityFloor: 0.1);
        $result  = $this->selector->select(['2024-05-02' => $summary], $this->createHome(), $options);

        $members = $result->getMembers();
        self::assertCount(2, $members);
        self::assertContains($morningPreferred, $members);
        self::assertContains($afternoon, $members);
        self::assertNotContains($morningOther, $members);
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

        $summary = $this->createDaySummary('2024-05-06', [$plain, $faces, $video]);

        $options = new VacationSelectionOptions(
            targetTotal: 2,
            maxPerDay: 2,
            videoBonus: 0.3,
            faceBonus: 0.4,
            selfiePenalty: 0.0,
            qualityFloor: 0.1,
            timeSlotHours: 6,
        );

        $result  = $this->selector->select(['2024-05-06' => $summary], $this->createHome(), $options);
        $members = $result->getMembers();

        self::assertCount(2, $members);
        self::assertContains($faces, $members);
        self::assertContains($video, $members);
        self::assertNotContains($plain, $members);
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

        self::assertCount(3, $members);
        self::assertSame(2, $telemetry['minimum_total']);
        self::assertTrue($telemetry['minimum_total_met']);
        self::assertCount(1, $telemetry['relaxations']);

        $relaxation = $telemetry['relaxations'][0];
        self::assertSame('min_spacing_seconds', $relaxation['rule']);
        self::assertSame(1200, $relaxation['from']);
        self::assertSame(0, $relaxation['to']);
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

        self::assertCount(3, $members);
        self::assertTrue($telemetry['minimum_total_met']);

        $rules = array_map(static fn (array $entry): string => $entry['rule'], $telemetry['relaxations']);
        self::assertContains('min_spacing_seconds', $rules);
        self::assertContains('phash_min_hamming', $rules);
        self::assertContains('max_per_day', $rules);
        self::assertContains('max_per_staypoint', $rules);
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
            'cohortPresenceRatio'     => 0.0,
            'cohortMembers'           => [],
            'baseLocation'            => null,
            'baseAway'                => false,
            'awayByDistance'          => false,
            'firstGpsMedia'           => $members[0] ?? null,
            'lastGpsMedia'            => $members[count($members) - 1] ?? null,
            'timezoneIdentifierVotes' => [],
            'isSynthetic'             => false,
        ];

        return array_replace($base, $overrides);
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
