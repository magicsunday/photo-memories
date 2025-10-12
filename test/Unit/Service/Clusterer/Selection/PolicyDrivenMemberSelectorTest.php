<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Selection;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Selection\MemberSelectionContext;
use MagicSunday\Memories\Service\Clusterer\Selection\PolicyDrivenMemberSelector;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicy;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\OrientationBalanceStage;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\PeopleBalanceStage;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\PhashDiversityStage;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\SceneDiversityStage;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\StaypointQuotaStage;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\TimeGapStage;
use MagicSunday\Memories\Test\Support\EntityIdAssignmentTrait;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use ReflectionMethod;

final class PolicyDrivenMemberSelectorTest extends TestCase
{
    use EntityIdAssignmentTrait;

    #[Test]
    public function stagesAreRegisteredInExpectedOrder(): void
    {
        $selector = $this->createSelector();

        $hardProperty = new ReflectionProperty(PolicyDrivenMemberSelector::class, 'hardStages');
        $softProperty = new ReflectionProperty(PolicyDrivenMemberSelector::class, 'softStages');
        $hardProperty->setAccessible(true);
        $softProperty->setAccessible(true);

        $hard = $hardProperty->getValue($selector);
        $soft = $softProperty->getValue($selector);

        self::assertSame([
            TimeGapStage::class,
            StaypointQuotaStage::class,
            PhashDiversityStage::class,
        ], array_map(static fn (object $stage): string => $stage::class, $hard));

        self::assertSame([
            SceneDiversityStage::class,
            OrientationBalanceStage::class,
            PeopleBalanceStage::class,
        ], array_map(static fn (object $stage): string => $stage::class, $soft));
    }

    #[Test]
    public function telemetryCollectsRejectionsForVacationPolicy(): void
    {
        $selector = $this->createSelector();
        $policy   = $this->createVacationPolicy();
        $mediaMap = $this->mediaFixtures();
        $memberIds = array_keys($mediaMap);

        $context = new MemberSelectionContext(
            $this->createDraft('vacation', $memberIds, 'vacation.transit'),
            $policy,
            $mediaMap,
            $this->qualityScores(),
        );

        $result = $selector->select('vacation', $memberIds, $context);
        $telemetry = $result->getTelemetry();

        self::assertSame($policy->getProfileKey(), $telemetry['policy']['profile']);
        self::assertSame('vacation.transit', $telemetry['storyline']);
        self::assertSame('vacation.transit', $telemetry['policy']['storyline']);
        self::assertSame(10, $telemetry['counts']['considered']);
        self::assertArrayHasKey('faces', $telemetry['metrics']);

        $rejections = $telemetry['rejections'];
        foreach ([
            'time_gap',
            'staypoint_quota',
            'phash_similarity',
            'scene_balance',
            'orientation_balance',
            'people_balance',
        ] as $key) {
            self::assertArrayHasKey($key, $rejections);
            self::assertGreaterThanOrEqual(0, $rejections[$key]);
        }

        self::assertGreaterThan(0, $rejections['time_gap']);
        self::assertGreaterThan(0, $rejections['phash_similarity']);
    }

    #[Test]
    public function telemetryCollectsRejectionsForHighlightsPolicy(): void
    {
        $selector = $this->createSelector();
        $policy   = $this->createHighlightsPolicy();
        $mediaMap = $this->mediaFixtures();
        $memberIds = array_keys($mediaMap);

        $context = new MemberSelectionContext(
            $this->createDraft('highlights', $memberIds, 'highlights.sprint'),
            $policy,
            $mediaMap,
            $this->qualityScores(),
        );

        $result    = $selector->select('highlights', $memberIds, $context);
        $telemetry = $result->getTelemetry();

        self::assertSame($policy->getProfileKey(), $telemetry['policy']['profile']);
        self::assertSame('highlights.sprint', $telemetry['storyline']);
        self::assertSame('highlights.sprint', $telemetry['policy']['storyline']);
        self::assertSame(10, $telemetry['counts']['considered']);
        self::assertArrayHasKey('faces', $telemetry['metrics']);

        $rejections = $telemetry['rejections'];
        foreach ([
            'time_gap',
            'staypoint_quota',
            'phash_similarity',
            'scene_balance',
            'orientation_balance',
            'people_balance',
        ] as $key) {
            self::assertArrayHasKey($key, $rejections);
            self::assertGreaterThanOrEqual(0, $rejections[$key]);
        }

        self::assertGreaterThan(0, $rejections['time_gap']);
        self::assertGreaterThan(0, $rejections['phash_similarity']);
    }

    #[Test]
    public function groupFaceBonusSurvivesBurstCollapse(): void
    {
        $selector = $this->createSelector();
        $policy   = $this->createVacationPolicy();

        $groupShot = $this->createMedia(101, '2024-05-20T10:00:00+02:00', 'aaaaaaaaaaaaaaa0', [], [52.5, 13.4], [4000, 3000]);
        $groupShot->setFacesCount(4);
        $groupShot->setHasFaces(true);
        $groupShot->setFeatures([
            'faces' => ['largest_coverage' => 0.32],
        ]);
        $groupShot->setBurstUuid('burst-group');

        $single = $this->createMedia(102, '2024-05-20T10:00:01+02:00', 'bbbbbbbbbbbbbbb0', [], [52.5, 13.4], [4000, 3000]);
        $single->setFacesCount(1);
        $single->setHasFaces(true);
        $single->setFeatures([
            'faces' => ['largest_coverage' => 0.58],
        ]);
        $single->setBurstUuid('burst-group');

        $memberIds   = [101, 102];
        $mediaMap    = [101 => $groupShot, 102 => $single];
        $quality     = [101 => 0.7, 102 => 0.7];
        $draft       = $this->createDraft('vacation', $memberIds);
        $candidates  = $this->invokeBuildCandidates($selector, $memberIds, $mediaMap, $quality, $policy, $draft);

        self::assertSame(1, $candidates['drops']['burst']);
        self::assertCount(1, $candidates['eligible']);
        self::assertSame(101, $candidates['eligible'][0]['id']);
    }

    #[Test]
    public function dominantCloseUpLosesBurstCompetition(): void
    {
        $selector = $this->createSelector();
        $policy   = $this->createVacationPolicy();

        $balanced = $this->createMedia(201, '2024-05-20T12:00:00+02:00', 'ccccccccccccccc0', [], [52.6, 13.5], [4000, 3000]);
        $balanced->setFacesCount(2);
        $balanced->setHasFaces(true);
        $balanced->setFeatures([
            'faces' => ['largest_coverage' => 0.36],
        ]);
        $balanced->setBurstUuid('burst-close');

        $closeUp = $this->createMedia(202, '2024-05-20T12:00:01+02:00', 'ddddddddddddddd0', [], [52.6, 13.5], [4000, 3000]);
        $closeUp->setFacesCount(1);
        $closeUp->setHasFaces(true);
        $closeUp->setFeatures([
            'faces' => ['largest_coverage' => 0.82],
        ]);
        $closeUp->setBurstUuid('burst-close');

        $memberIds   = [201, 202];
        $mediaMap    = [201 => $balanced, 202 => $closeUp];
        $quality     = [201 => 0.7, 202 => 0.7];
        $draft       = $this->createDraft('vacation', $memberIds);
        $candidates  = $this->invokeBuildCandidates($selector, $memberIds, $mediaMap, $quality, $policy, $draft);

        self::assertSame(1, $candidates['drops']['burst']);
        self::assertCount(1, $candidates['eligible']);
        self::assertSame(201, $candidates['eligible'][0]['id']);
    }

    #[Test]
    public function buildCandidatesAnnotatesSceneBuckets(): void
    {
        $selector = $this->createSelector();
        $policy   = $this->createVacationPolicy();

        $group = $this->createMedia(301, '2024-05-18T18:00:00+02:00', 'aaaaaaaaaaaaaaa1', [], [52.4, 13.4], [4000, 3000]);
        $group->setFacesCount(4);
        $group->setHasFaces(true);
        $group->setFeatures([
            'faces' => ['largest_coverage' => 0.30],
        ]);

        $panorama = $this->createMedia(302, '2024-05-18T11:00:00+02:00', 'bbbbbbbbbbbbbbb1', [], [52.5, 13.5], [8000, 2000]);
        $panorama->setIsPanorama(true);

        $night = $this->createMedia(303, '2024-05-18T22:30:00+02:00', 'ccccccccccccccc1', [], [52.6, 13.6], [4000, 3000]);
        $night->setFeatures([
            'calendar' => ['daypart' => 'night'],
        ]);
        $night->setSceneTags([
            ['label' => 'Night city lights', 'score' => 0.92],
        ]);

        $food = $this->createMedia(304, '2024-05-18T12:30:00+02:00', 'ddddddddddddddd1', [], [52.7, 13.7], [4000, 3000]);
        $food->setSceneTags([
            ['label' => 'Delicious food spread', 'score' => 0.95],
        ]);

        $memberIds = [301, 302, 303, 304];
        $mediaMap  = [301 => $group, 302 => $panorama, 303 => $night, 304 => $food];
        $quality   = [301 => 0.8, 302 => 0.8, 303 => 0.8, 304 => 0.8];
        $draft     = $this->createDraft('vacation', $memberIds);

        $candidates = $this->invokeBuildCandidates($selector, $memberIds, $mediaMap, $quality, $policy, $draft);

        $eligible = $candidates['eligible'];
        self::assertCount(4, $eligible);

        $buckets = array_map(static fn (array $candidate): string => $candidate['bucket'], $eligible);

        self::assertContains('person_group', $buckets);
        self::assertContains('panorama', $buckets);
        self::assertContains('night', $buckets);
        self::assertContains('food', $buckets);
        self::assertGreaterThanOrEqual(4, count(array_values(array_unique($buckets))));
    }

    #[Test]
    public function sceneDiversityStageHonoursTargetShare(): void
    {
        $stage = new SceneDiversityStage();
        $policy = new SelectionPolicy(
            profileKey: 'test',
            targetTotal: 8,
            minimumTotal: 4,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 0,
            phashMinHamming: 0,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: [
                'person_group' => 0.25,
                'outdoor'      => 0.75,
            ],
        );

        $candidates = [
            ['id' => 1, 'timestamp' => 1, 'score' => 1.0, 'bucket' => 'person_group'],
            ['id' => 2, 'timestamp' => 2, 'score' => 1.0, 'bucket' => 'person_group'],
            ['id' => 3, 'timestamp' => 3, 'score' => 1.0, 'bucket' => 'outdoor'],
            ['id' => 4, 'timestamp' => 4, 'score' => 1.0, 'bucket' => 'outdoor'],
            ['id' => 5, 'timestamp' => 5, 'score' => 1.0, 'bucket' => 'outdoor'],
        ];

        $telemetry = new SelectionTelemetry();
        $result    = $stage->apply($candidates, $policy, $telemetry);

        $personGroupSelected = array_filter(
            $result,
            static fn (array $candidate): bool => $candidate['bucket'] === 'person_group'
        );

        self::assertCount(1, $personGroupSelected);

        $reasons = $telemetry->reasonCounts();
        self::assertGreaterThan(0, $reasons[SelectionTelemetry::REASON_SCENE]);
    }

    /**
     * @return array<int, Media>
     */
    private function mediaFixtures(): array
    {
        return [
            1  => $this->createMedia(1, '2024-05-16T10:00:00+02:00', 'fffffffffffffff0', ['Alice'], [52.500, 13.400], [4000, 3000]),
            2  => $this->createMedia(2, '2024-05-16T10:20:00+02:00', 'fffffffffffffff1', ['Alice'], [52.500, 13.400], [4000, 3000]),
            3  => $this->createMedia(3, '2024-05-16T14:00:00+02:00', '0fffffffffffffff', ['Bob'], [52.500, 13.400], [3000, 4000]),
            4  => $this->createMedia(4, '2024-05-16T18:00:00+02:00', '1fffffffffffffff', ['Alice'], [52.500, 13.400], [4000, 3000]),
            5  => $this->createMedia(5, '2024-05-17T09:00:00+02:00', 'fffffffffffffff0', ['Alice'], [52.600, 13.500], [4000, 3000]),
            6  => $this->createMedia(6, '2024-05-17T12:00:00+02:00', '2fffffffffffffff', ['Alice'], [52.700, 13.600], [4000, 3000]),
            7  => $this->createMedia(7, '2024-05-17T18:00:00+02:00', '3fffffffffffffff', ['Alice'], [52.800, 13.700], [4000, 3000]),
            8  => $this->createMedia(8, '2024-05-17T21:00:00+02:00', '4fffffffffffffff', ['Alice'], [52.850, 13.750], [4000, 3000]),
            9  => $this->createMedia(9, '2024-05-18T12:00:00+02:00', '5fffffffffffffff', ['Alice'], [52.900, 13.800], [4000, 3000]),
            10 => $this->createMedia(10, '2024-05-19T12:00:00+02:00', '6fffffffffffffff', ['Alice'], [53.000, 13.900], [3000, 4000]),
        ];
    }

    /**
     * @return array<int, float>
     */
    private function qualityScores(): array
    {
        return [
            1 => 0.7,
            2 => 0.7,
            3 => 0.7,
            4 => 0.7,
            5 => 0.7,
            6 => 0.7,
            7 => 0.7,
            8 => 0.7,
            9 => 0.7,
            10 => 0.7,
        ];
    }

    private function createSelector(): PolicyDrivenMemberSelector
    {
        return new PolicyDrivenMemberSelector(
            hardStages: [
                new TimeGapStage(),
                new StaypointQuotaStage(),
                new PhashDiversityStage(),
            ],
            softStages: [
                new SceneDiversityStage(),
                new OrientationBalanceStage(),
                new PeopleBalanceStage(),
            ],
        );
    }

    private function createVacationPolicy(): SelectionPolicy
    {
        return new SelectionPolicy(
            profileKey: 'vacation',
            targetTotal: 72,
            minimumTotal: 48,
            maxPerDay: 6,
            timeSlotHours: 3.0,
            minSpacingSeconds: 1800,
            phashMinHamming: 8,
            maxPerStaypoint: 1,
            relaxedMaxPerStaypoint: 2,
            qualityFloor: 0.6,
            videoBonus: 0.28,
            faceBonus: 0.36,
            selfiePenalty: 0.22,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: 0.34,
        );
    }

    private function createHighlightsPolicy(): SelectionPolicy
    {
        return new SelectionPolicy(
            profileKey: 'highlights',
            targetTotal: 34,
            minimumTotal: 26,
            maxPerDay: 4,
            timeSlotHours: 3.0,
            minSpacingSeconds: 2100,
            phashMinHamming: 9,
            maxPerStaypoint: 2,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.6,
            videoBonus: 0.38,
            faceBonus: 0.36,
            selfiePenalty: 0.24,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: 0.32,
        );
    }

    /**
     * @param list<int> $memberIds
     */
    private function createDraft(string $algorithm, array $memberIds, ?string $storyline = null): ClusterDraft
    {
        $storyline ??= $algorithm . '.default';

        return new ClusterDraft(
            algorithm: $algorithm,
            params: [],
            centroid: ['lat' => 52.5, 'lon' => 13.4],
            members: $memberIds,
            storyline: $storyline,
        );
    }

    /**
     * @param list<int> $memberIds
     * @param array<int, Media> $mediaMap
     * @param array<int, float|null> $qualityScores
     *
     * @return array{eligible: list<array<string, mixed>>, drops: array<string, int>, all: list<int>}
     */
    private function invokeBuildCandidates(
        PolicyDrivenMemberSelector $selector,
        array $memberIds,
        array $mediaMap,
        array $qualityScores,
        SelectionPolicy $policy,
        ClusterDraft $draft,
    ): array {
        $method = new ReflectionMethod(PolicyDrivenMemberSelector::class, 'buildCandidates');
        $method->setAccessible(true);

        /** @var array{eligible: list<array<string, mixed>>, drops: array<string, int>, all: list<int>} $result */
        $result = $method->invoke($selector, $memberIds, $mediaMap, $qualityScores, $policy, $draft);

        return $result;
    }

    private function createMedia(
        int $id,
        string $takenAt,
        string $phash,
        array $persons,
        array $coords,
        array $size,
    ): Media {
        $media = new Media('/tmp/media_' . $id . '.jpg', 'checksum-' . $id, 1000);
        $this->assignEntityId($media, $id);

        $timestamp = new DateTimeImmutable($takenAt);
        $media->setTakenAt($timestamp);
        $media->setQualityScore(0.7);
        $media->setPhash($phash);
        $media->setPhash64($phash);
        $media->setPersons($persons);
        $media->setIsVideo(false);
        $media->setNoShow(false);
        $media->setGpsLat($coords[0]);
        $media->setGpsLon($coords[1]);
        $media->setWidth($size[0]);
        $media->setHeight($size[1]);
        $media->setOrientation($size[0] === $size[1] ? 1 : ($size[0] > $size[1] ? 1 : 6));

        return $media;
    }
}
