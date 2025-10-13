<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Pipeline;

use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberCurationStage;
use MagicSunday\Memories\Service\Clusterer\Pipeline\MemberMediaLookupInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\ClusterMemberSelectorInterface;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicy;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicyProvider;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

use function array_sum;

final class MemberCurationStageTest extends TestCase
{
    #[Test]
    public function peripheralDaysAreTrimmedToAggregateLimit(): void
    {
        $stage = new MemberCurationStage(
            mediaLookup: $this->createStub(MemberMediaLookupInterface::class),
            policyProvider: $this->createStub(SelectionPolicyProvider::class),
            selector: $this->createStub(ClusterMemberSelectorInterface::class),
        );

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
        $stage = new MemberCurationStage(
            mediaLookup: $this->createStub(MemberMediaLookupInterface::class),
            policyProvider: $this->createStub(SelectionPolicyProvider::class),
            selector: $this->createStub(ClusterMemberSelectorInterface::class),
        );

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
        self::assertSame(1, $quotas['2024-03-04']);
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
}
