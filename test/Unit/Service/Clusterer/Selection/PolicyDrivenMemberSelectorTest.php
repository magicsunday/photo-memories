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
            $this->createDraft('vacation', $memberIds),
            $policy,
            $mediaMap,
            $this->qualityScores(),
        );

        $result = $selector->select('vacation', $memberIds, $context);
        $telemetry = $result->getTelemetry();

        self::assertSame($policy->getProfileKey(), $telemetry['policy']['profile']);
        self::assertSame(10, $telemetry['counts']['considered']);

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
            $this->createDraft('highlights', $memberIds),
            $policy,
            $mediaMap,
            $this->qualityScores(),
        );

        $result    = $selector->select('highlights', $memberIds, $context);
        $telemetry = $result->getTelemetry();

        self::assertSame($policy->getProfileKey(), $telemetry['policy']['profile']);
        self::assertSame(10, $telemetry['counts']['considered']);

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
            targetTotal: 60,
            minimumTotal: 36,
            maxPerDay: 5,
            timeSlotHours: 4.0,
            minSpacingSeconds: 2400,
            phashMinHamming: 9,
            maxPerStaypoint: 2,
            qualityFloor: 0.54,
            videoBonus: 0.22,
            faceBonus: 0.32,
            selfiePenalty: 0.25,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: 0.25,
        );
    }

    private function createHighlightsPolicy(): SelectionPolicy
    {
        return new SelectionPolicy(
            profileKey: 'highlights',
            targetTotal: 30,
            minimumTotal: 24,
            maxPerDay: 4,
            timeSlotHours: 4.0,
            minSpacingSeconds: 2400,
            phashMinHamming: 10,
            maxPerStaypoint: 2,
            qualityFloor: 0.56,
            videoBonus: 0.35,
            faceBonus: 0.35,
            selfiePenalty: 0.25,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: 0.28,
        );
    }

    /**
     * @param list<int> $memberIds
     */
    private function createDraft(string $algorithm, array $memberIds): ClusterDraft
    {
        return new ClusterDraft(
            algorithm: $algorithm,
            params: [],
            centroid: ['lat' => 52.5, 'lon' => 13.4],
            members: $memberIds,
        );
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
