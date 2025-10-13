<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Selection\Stage;

use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicy;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\TimeSlotDiversificationStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function floor;

final class TimeSlotDiversificationStageTest extends TestCase
{
    #[Test]
    public function itBlocksCandidatesCloserThanQuotaDerivedSpacing(): void
    {
        $stage     = new TimeSlotDiversificationStage();
        $telemetry = new SelectionTelemetry();
        $policy    = $this->createPolicy(
            targetTotal: 6,
            minSpacingSeconds: 600,
            dayQuotas: ['2024-03-01' => 2],
            dayContext: [
                '2024-03-01' => [
                    'score' => 1.0,
                    'category' => 'core',
                    'duration' => 7200,
                    'metrics' => [],
                ],
            ],
        );

        $candidates = [
            ['id' => 1, 'day' => '2024-03-01', 'timestamp' => 0, 'day_duration' => 7200],
            ['id' => 2, 'day' => '2024-03-01', 'timestamp' => 2_000, 'day_duration' => 7200],
        ];

        $result = $stage->apply($candidates, $policy, $telemetry);

        self::assertSame([1], array_column($result, 'id'));

        $reasons = $telemetry->reasonCounts();
        self::assertSame(1, $reasons[SelectionTelemetry::REASON_TIME_SLOT]);
    }

    #[Test]
    public function itHonoursBaseSpacingWhenDayDurationIsShort(): void
    {
        $stage     = new TimeSlotDiversificationStage();
        $telemetry = new SelectionTelemetry();
        $policy    = $this->createPolicy(
            targetTotal: 4,
            minSpacingSeconds: 600,
            dayQuotas: ['2024-03-02' => 10],
        );

        $candidates = [
            ['id' => 10, 'day' => '2024-03-02', 'timestamp' => 0, 'day_duration' => 1_800],
            ['id' => 11, 'day' => '2024-03-02', 'timestamp' => 500, 'day_duration' => 1_800],
        ];

        $result = $stage->apply($candidates, $policy, $telemetry);

        self::assertSame([10], array_column($result, 'id'));

        $reasons = $telemetry->reasonCounts();
        self::assertSame(1, $reasons[SelectionTelemetry::REASON_TIME_SLOT]);
    }

    #[Test]
    public function itFallsBackToComputedCapWhenNoExplicitQuotasExist(): void
    {
        $stage     = new TimeSlotDiversificationStage();
        $telemetry = new SelectionTelemetry();
        $policy    = $this->createPolicy(
            targetTotal: 12,
            minSpacingSeconds: 300,
            dayQuotas: [],
            dayContext: [],
        );

        $candidates = [
            ['id' => 21, 'day' => '2024-03-03', 'timestamp' => 0, 'day_duration' => 7_200],
            ['id' => 22, 'day' => '2024-03-03', 'timestamp' => 1_000, 'day_duration' => 7_200],
            ['id' => 23, 'day' => '2024-03-04', 'timestamp' => 0, 'day_duration' => 3_600],
        ];

        $result = $stage->apply($candidates, $policy, $telemetry);

        self::assertSame([21, 23], array_column($result, 'id'));

        $reasons = $telemetry->reasonCounts();
        self::assertSame(1, $reasons[SelectionTelemetry::REASON_TIME_SLOT]);
    }

    private function createPolicy(
        int $targetTotal,
        int $minSpacingSeconds,
        array $dayQuotas,
        array $dayContext = [],
    ): SelectionPolicy {
        return new SelectionPolicy(
            profileKey: 'test',
            targetTotal: $targetTotal,
            minimumTotal: max(1, (int) floor($targetTotal / 2)),
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: $minSpacingSeconds,
            phashMinHamming: 0,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            dayQuotas: $dayQuotas,
            dayContext: $dayContext,
        );
    }
}

