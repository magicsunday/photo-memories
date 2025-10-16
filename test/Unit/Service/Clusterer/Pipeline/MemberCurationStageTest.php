<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Pipeline;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberCurationStage;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\ClusterMemberSelectorInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\MemberSelectionContext;
use MagicSunday\Memories\Service\Clusterer\Selection\MemberSelectionResult;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicy;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicyProvider;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;
use MagicSunday\Memories\Test\Unit\Clusterer\Fixtures\RecordingMonitoringEmitter;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

use function array_sum;

final class MemberCurationStageTest extends TestCase
{
    #[Test]
    public function peripheralDaysAreTrimmedToAggregateLimit(): void
    {
        $stage = $this->createStage();

        $policy = new SelectionPolicy(
            profileKey: 'test',
            targetTotal: 12,
            minimumTotal: 6,
            maxPerDay: 5,
            timeSlotHours: null,
            minSpacingSeconds: 30,
            phashMinHamming: 12,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.1,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 2,
            peripheralDayPenalty: 0,
            phashPercentile: 0.35,
            spacingProgressFactor: 0.5,
            cohortPenalty: 0.05,
        );

        $daySegments = [
            '2024-01-01' => ['score' => 0.9, 'category' => 'core', 'duration' => null, 'metrics' => []],
            '2024-01-02' => ['score' => 0.7, 'category' => 'peripheral', 'duration' => null, 'metrics' => []],
            '2024-01-03' => ['score' => 0.6, 'category' => 'peripheral', 'duration' => null, 'metrics' => []],
            '2024-01-04' => ['score' => 0.5, 'category' => 'peripheral', 'duration' => null, 'metrics' => []],
            '2024-01-05' => ['score' => 0.4, 'category' => 'peripheral', 'duration' => null, 'metrics' => []],
        ];

        $resultPolicy = $this->invokeApplyDayContext($stage, $policy, $daySegments);
        $quotas       = $resultPolicy->getDayQuotas();

        self::assertSame(4, $resultPolicy->getPeripheralDayMaxTotal());
        self::assertSame(2, $resultPolicy->getPeripheralDayHardCap());
        self::assertSame(1, $quotas['2024-01-02']);
        self::assertSame(1, $quotas['2024-01-03']);
        self::assertSame(1, $quotas['2024-01-04']);
        self::assertSame(1, $quotas['2024-01-05']);
        self::assertSame(4, array_sum([
            $quotas['2024-01-02'],
            $quotas['2024-01-03'],
            $quotas['2024-01-04'],
            $quotas['2024-01-05'],
        ]));
        self::assertGreaterThan($quotas['2024-01-02'], $quotas['2024-01-01']);
    }

    #[Test]
    public function peripheralDayHardCapFallsBackToOneForTightBudgets(): void
    {
        $stage = $this->createStage();

        $policy = new SelectionPolicy(
            profileKey: 'test',
            targetTotal: 5,
            minimumTotal: 3,
            maxPerDay: 4,
            timeSlotHours: null,
            minSpacingSeconds: 30,
            phashMinHamming: 12,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.1,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 1,
            peripheralDayPenalty: 0,
            phashPercentile: 0.35,
            spacingProgressFactor: 0.5,
            cohortPenalty: 0.05,
        );

        $daySegments = [
            '2024-03-01' => ['score' => 0.8, 'category' => 'core', 'duration' => null, 'metrics' => []],
            '2024-03-02' => ['score' => 0.7, 'category' => 'peripheral', 'duration' => null, 'metrics' => []],
            '2024-03-03' => ['score' => 0.6, 'category' => 'peripheral', 'duration' => null, 'metrics' => []],
            '2024-03-04' => ['score' => 0.5, 'category' => 'peripheral', 'duration' => null, 'metrics' => []],
        ];

        $resultPolicy = $this->invokeApplyDayContext($stage, $policy, $daySegments);
        $quotas       = $resultPolicy->getDayQuotas();

        self::assertSame(3, $resultPolicy->getPeripheralDayMaxTotal());
        self::assertSame(1, $resultPolicy->getPeripheralDayHardCap());
        self::assertSame(1, $quotas['2024-03-02']);
        self::assertSame(1, $quotas['2024-03-03']);
        self::assertSame(0, $quotas['2024-03-04']);
    }

    #[Test]
    public function dayQuotasAreNormalizedToTargetTotal(): void
    {
        $stage = $this->createStage();

        $policy = new SelectionPolicy(
            profileKey: 'test',
            targetTotal: 8,
            minimumTotal: 4,
            maxPerDay: 6,
            timeSlotHours: null,
            minSpacingSeconds: 30,
            phashMinHamming: 12,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.1,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 2,
            peripheralDayPenalty: 0,
            phashPercentile: 0.35,
            spacingProgressFactor: 0.5,
            cohortPenalty: 0.05,
        );

        $daySegments = [
            '2024-04-01' => ['score' => 0.9, 'category' => 'core', 'duration' => null, 'metrics' => []],
            '2024-04-02' => ['score' => 0.3, 'category' => 'core', 'duration' => null, 'metrics' => []],
            '2024-04-03' => ['score' => 0.5, 'category' => 'peripheral', 'duration' => null, 'metrics' => []],
        ];

        $resultPolicy = $this->invokeApplyDayContext($stage, $policy, $daySegments);
        $quotas       = $resultPolicy->getDayQuotas();

        self::assertSame(5, $quotas['2024-04-01']);
        self::assertSame(3, $quotas['2024-04-02']);
        self::assertSame(0, $quotas['2024-04-03']);
        self::assertSame(8, array_sum($quotas));
        self::assertSame(2, $resultPolicy->getPeripheralDayHardCap());
    }

    #[Test]
    public function processKeepsRawMembersAndAnnotatesQuality(): void
    {
        $mediaA = $this->createConfiguredMock(Media::class, ['getId' => 1, 'isVideo' => false]);
        $mediaB = $this->createConfiguredMock(Media::class, ['getId' => 2, 'isVideo' => true]);
        $mediaC = $this->createConfiguredMock(Media::class, ['getId' => 3, 'isVideo' => false]);

        $lookup = new class([$mediaA, $mediaB, $mediaC]) implements MemberMediaLookupInterface {
            /**
             * @param list<Media> $media
             */
            public function __construct(private readonly array $media)
            {
            }

            public function findByIds(array $ids, bool $onlyVideos = false): array
            {
                $map = [];
                foreach ($this->media as $item) {
                    $map[$item->getId()] = $item;
                }

                $result = [];
                foreach ($ids as $id) {
                    if (isset($map[$id])) {
                        $result[] = $map[$id];
                    }
                }

                return $result;
            }
        };

        $selector = new class implements ClusterMemberSelectorInterface {
            public function select(string $algorithm, array $memberIds, ?MemberSelectionContext $context = null): MemberSelectionResult
            {
                MemberCurationStageTest::assertSame('demo', $algorithm);
                MemberCurationStageTest::assertSame([1, 2, 3], $memberIds);

                return new MemberSelectionResult(
                    memberIds: [1, 3],
                    telemetry: [
                        'distribution' => [
                            'per_day'   => ['2024-07-01' => 2],
                            'per_year'  => ['2024' => 2],
                            'per_bucket'=> ['core' => 2],
                        ],
                        'metrics' => [
                            'time_gaps'        => [120, 240],
                            'phash_distances'  => [0.25, 0.35],
                        ],
                        'rejections' => [
                            SelectionTelemetry::REASON_PHASH    => 1,
                            SelectionTelemetry::REASON_TIME_GAP => 1,
                        ],
                    ],
                );
            }
        };

        $profiles = [
            'default' => [
                'target_total'            => 2,
                'minimum_total'           => 1,
                'max_per_day'             => null,
                'time_slot_hours'         => null,
                'min_spacing_seconds'     => 30,
                'phash_min_hamming'       => 8,
                'max_per_staypoint'       => null,
                'max_per_staypoint_relaxed' => null,
                'quality_floor'           => 0.0,
                'video_bonus'             => 0.0,
                'face_bonus'              => 0.0,
                'selfie_penalty'          => 0.0,
                'max_per_year'            => null,
                'max_per_bucket'          => null,
                'video_heavy_bonus'       => null,
                'scene_bucket_weights'    => null,
                'core_day_bonus'          => 1,
                'peripheral_day_penalty'  => 0,
                'phash_percentile'        => 0.35,
                'spacing_progress_factor' => 0.5,
                'cohort_repeat_penalty'   => 0.05,
            ],
        ];

        $stage = new MemberCurationStage(
            mediaLookup: $lookup,
            policyProvider: new SelectionPolicyProvider($profiles, 'default'),
            selector: $selector,
        );

        $draft = new ClusterDraft('demo', ['storyline' => 'demo'], ['lat' => 48.1, 'lon' => 11.5], [1, 2, 3], 'demo');

        $processedDrafts = $stage->process([$draft]);

        self::assertCount(1, $processedDrafts);
        $processed = $processedDrafts[0];
        self::assertSame([1, 2, 3], $processed->getMembers());

        $params    = $processed->getParams();
        $selection = $params['member_selection'];
        self::assertSame(['raw' => 3, 'curated' => 2, 'dropped' => 1], $selection['counts']);
        self::assertSame(1, $selection['rejection_counts'][SelectionTelemetry::REASON_PHASH]);

        $quality = $params['member_quality'];
        self::assertSame([1, 3], $quality['ordered']);
        $summary = $quality['summary'];
        self::assertSame(['raw' => 3, 'curated' => 2, 'dropped' => 1], $summary['selection_counts']);
        self::assertSame(['2024-07-01' => 2], $summary['selection_per_day_distribution']);
        self::assertSame(1, $summary['selection_near_duplicates']['blocked']);
        self::assertSame('demo', $summary['selection_storyline']);
        self::assertSame('demo', $params['storyline']);
        self::assertArrayHasKey('selection_telemetry', $summary);
    }

    #[Test]
    public function processEmitsMonitoringCountsAndPolicy(): void
    {
        $mediaA = $this->createConfiguredMock(Media::class, ['getId' => 1, 'isVideo' => false]);
        $mediaB = $this->createConfiguredMock(Media::class, ['getId' => 2, 'isVideo' => true]);
        $mediaC = $this->createConfiguredMock(Media::class, ['getId' => 3, 'isVideo' => false]);

        $lookup = new class([$mediaA, $mediaB, $mediaC]) implements MemberMediaLookupInterface {
            /**
             * @param list<Media> $media
             */
            public function __construct(private readonly array $media)
            {
            }

            public function findByIds(array $ids, bool $onlyVideos = false): array
            {
                $map = [];
                foreach ($this->media as $item) {
                    $map[$item->getId()] = $item;
                }

                $result = [];
                foreach ($ids as $id) {
                    if (isset($map[$id])) {
                        $result[] = $map[$id];
                    }
                }

                return $result;
            }
        };

        $profiles = [
            'default' => [
                'target_total' => 4,
                'minimum_total' => 1,
                'max_per_day' => null,
                'time_slot_hours' => null,
                'min_spacing_seconds' => 1,
                'phash_min_hamming' => 1,
                'max_per_staypoint' => null,
                'max_per_staypoint_relaxed' => null,
                'quality_floor' => 0.0,
                'video_bonus' => 0.0,
                'face_bonus' => 0.0,
                'selfie_penalty' => 0.0,
                'max_per_year' => null,
                'max_per_bucket' => null,
                'video_heavy_bonus' => null,
                'scene_bucket_weights' => null,
                'core_day_bonus' => 0,
                'peripheral_day_penalty' => 0,
                'phash_percentile' => 0.35,
                'spacing_progress_factor' => 0.5,
                'cohort_repeat_penalty' => 0.05,
            ],
        ];

        $policyProvider = new SelectionPolicyProvider($profiles, 'default');

        $selector = $this->createMock(ClusterMemberSelectorInterface::class);
        $selector->expects(self::once())
            ->method('select')
            ->willReturn(new MemberSelectionResult([1, 3], [
                'rejections' => [SelectionTelemetry::REASON_TIME_GAP => 1],
            ]));

        $emitter = new RecordingMonitoringEmitter();

        $stage = new MemberCurationStage($lookup, $policyProvider, $selector, $emitter);

        $draft = new ClusterDraft('algo', ['member_quality' => ['members' => []]], ['lat' => 48.1, 'lon' => 11.5], [1, 2, 3], 'demo.story');

        $stage->process([$draft]);

        self::assertCount(2, $emitter->events);

        $completed = $emitter->events[1];
        self::assertSame('member_curation', $completed['job']);
        self::assertSame('selection_completed', $completed['status']);

        $context = $completed['context'];
        self::assertSame(3, $context['members_pre']);
        self::assertSame(2, $context['members_curated']);
        self::assertSame('default', $context['policy_key']);
    }

    #[Test]
    public function normalizationRespectsMinimumFloorWhenTargetIsSmaller(): void
    {
        $stage = $this->createStage();

        $policy = new SelectionPolicy(
            profileKey: 'test',
            targetTotal: 3,
            minimumTotal: 3,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 30,
            phashMinHamming: 12,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.1,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 0,
            peripheralDayPenalty: 0,
            phashPercentile: 0.35,
            spacingProgressFactor: 0.5,
            cohortPenalty: 0.05,
        );

        $daySegments = [
            '2024-06-01' => ['score' => 0.9, 'category' => 'core', 'duration' => null, 'metrics' => []],
            '2024-06-02' => ['score' => 0.8, 'category' => 'core', 'duration' => null, 'metrics' => []],
            '2024-06-03' => ['score' => 0.7, 'category' => 'core', 'duration' => null, 'metrics' => []],
            '2024-06-04' => ['score' => 0.6, 'category' => 'core', 'duration' => null, 'metrics' => []],
        ];

        $resultPolicy = $this->invokeApplyDayContext($stage, $policy, $daySegments);
        $quotas       = $resultPolicy->getDayQuotas();

        self::assertSame(4, array_sum($quotas));
        foreach ($daySegments as $day => $_) {
            self::assertSame(1, $quotas[$day]);
        }
    }

    private function invokeApplyDayContext(
        MemberCurationStage $stage,
        SelectionPolicy $policy,
        array $daySegments,
    ): SelectionPolicy {
        $reflection = new ReflectionClass(MemberCurationStage::class);
        $method     = $reflection->getMethod('applyDayContext');
        $method->setAccessible(true);

        /** @var SelectionPolicy $result */
        $result = $method->invoke($stage, $policy, $daySegments);

        return $result;
    }

    private function createStage(): MemberCurationStage
    {
        $profiles = [
            'default' => [
                'target_total' => 1,
                'minimum_total' => 1,
                'max_per_day' => null,
                'time_slot_hours' => null,
                'min_spacing_seconds' => 1,
                'phash_min_hamming' => 1,
                'max_per_staypoint' => null,
                'max_per_staypoint_relaxed' => null,
                'quality_floor' => 0.0,
                'video_bonus' => 0.0,
                'face_bonus' => 0.0,
                'selfie_penalty' => 0.0,
                'max_per_year' => null,
                'max_per_bucket' => null,
                'video_heavy_bonus' => null,
                'scene_bucket_weights' => null,
                'core_day_bonus' => 1,
                'peripheral_day_penalty' => 1,
                'phash_percentile' => 0.35,
                'spacing_progress_factor' => 0.5,
                'cohort_repeat_penalty' => 0.05,
            ],
        ];

        $policyProvider = new SelectionPolicyProvider($profiles, 'default');

        return new MemberCurationStage(
            mediaLookup: $this->createStub(MemberMediaLookupInterface::class),
            policyProvider: $policyProvider,
            selector: $this->createStub(ClusterMemberSelectorInterface::class),
        );
    }
}
