<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Selection;

use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicy;
use MagicSunday\Memories\Service\Clusterer\Selection\ValueFactory;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ValueFactoryTest extends TestCase
{
    #[Test]
    public function itComputesRunDayCapAndQuotaSpacing(): void
    {
        $policy = new SelectionPolicy(
            profileKey: 'test',
            targetTotal: 6,
            minimumTotal: 3,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 300,
            phashMinHamming: 8,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.1,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            mmrLambda: 0.5,
            mmrSimilarityFloor: 0.2,
            mmrSimilarityCap: 0.9,
            mmrMaxConsideration: 64,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 1,
            peripheralDayPenalty: 1,
            phashPercentile: 0.35,
            spacingProgressFactor: 0.5,
            cohortPenalty: 0.05,
            peripheralDayMaxTotal: null,
            peripheralDayHardCap: null,
            dayQuotas: [
                '2024-03-01' => 2,
                '2024-03-02' => 3,
            ],
            dayContext: [
                '2024-03-01' => ['score' => 1.0, 'category' => 'core', 'duration' => 7_200, 'metrics' => []],
                '2024-03-02' => ['score' => 0.5, 'category' => 'peripheral', 'duration' => 3_600, 'metrics' => []],
            ],
        );

        $candidates = [
            ['day' => '2024-03-01', 'day_duration' => 7_200],
            ['day' => '2024-03-01', 'day_duration' => 7_100],
            ['day' => '2024-03-02', 'day_duration' => 1_800],
        ];

        $factory = new ValueFactory();
        $derived = $factory->create($policy, $candidates, $policy->getDayContext());

        self::assertSame(2, $derived->runDays);
        self::assertSame(3, $derived->defaultPerDayCap);
        self::assertSame(['2024-03-01', '2024-03-02'], $derived->uniqueDays);
        self::assertSame(
            [
                '2024-03-01' => 2_400,
                '2024-03-02' => 450,
            ],
            $derived->quotaSpacingSeconds,
        );
    }

    #[Test]
    public function itFallsBackToComputedCapWhenQuotasMissing(): void
    {
        $policy = new SelectionPolicy(
            profileKey: 'fallback',
            targetTotal: 5,
            minimumTotal: 3,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 120,
            phashMinHamming: 4,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.1,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            mmrLambda: 0.5,
            mmrSimilarityFloor: 0.2,
            mmrSimilarityCap: 0.9,
            mmrMaxConsideration: 32,
            maxPerYear: null,
            maxPerBucket: null,
            videoHeavyBonus: null,
            sceneBucketWeights: null,
            coreDayBonus: 1,
            peripheralDayPenalty: 1,
            phashPercentile: 0.35,
            spacingProgressFactor: 0.5,
            cohortPenalty: 0.05,
            peripheralDayMaxTotal: null,
            peripheralDayHardCap: null,
            dayQuotas: [],
            dayContext: [
                '2024-04-10' => ['score' => 0.4, 'category' => 'peripheral', 'duration' => 5_400, 'metrics' => []],
            ],
        );

        $candidates = [
            ['day' => '2024-04-10', 'day_duration' => 5_400],
        ];

        $derived = (new ValueFactory())->create($policy, $candidates, $policy->getDayContext());

        self::assertSame(1, $derived->runDays);
        self::assertSame(5, $derived->defaultPerDayCap);
        self::assertSame(['2024-04-10'], $derived->uniqueDays);
        self::assertSame(900, $derived->quotaSpacingSeconds['2024-04-10']);
    }
}
