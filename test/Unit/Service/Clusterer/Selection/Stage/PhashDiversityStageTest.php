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
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\PhashDiversityStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;

final class PhashDiversityStageTest extends TestCase
{
    #[Test]
    public function adaptiveThresholdRespectsPolicyMinimum(): void
    {
        $stage     = new PhashDiversityStage();
        $policy    = $this->createPolicy(phashMinHamming: 10, phashPercentile: 0.1);
        $telemetry = new SelectionTelemetry();

        $candidates = [
            ['id' => 1, 'hash_bits' => [0, 0, 0, 0]],
            ['id' => 2, 'hash_bits' => [0, 0, 0, 1]],
        ];

        $result = $stage->apply($candidates, $policy, $telemetry);

        self::assertSame([1], array_column($result, 'id'));

        $reasons = $telemetry->reasonCounts();
        self::assertSame(1, $reasons[SelectionTelemetry::REASON_PHASH]);
    }

    #[Test]
    public function globalMinimumProtectsAgainstOverlyLowPolicies(): void
    {
        $stage     = new PhashDiversityStage();
        $policy    = $this->createPolicy(phashMinHamming: 4, phashPercentile: 0.1);
        $telemetry = new SelectionTelemetry();

        $candidates = [
            ['id' => 1, 'hash_bits' => [1, 1, 1, 1]],
            ['id' => 2, 'hash_bits' => [1, 1, 1, 0]],
        ];

        $result = $stage->apply($candidates, $policy, $telemetry);

        self::assertSame([1], array_column($result, 'id'));

        $reasons = $telemetry->reasonCounts();
        self::assertSame(1, $reasons[SelectionTelemetry::REASON_PHASH]);
    }

    private function createPolicy(int $phashMinHamming, float $phashPercentile): SelectionPolicy
    {
        return new SelectionPolicy(
            profileKey: 'test',
            targetTotal: 10,
            minimumTotal: 5,
            maxPerDay: null,
            timeSlotHours: null,
            minSpacingSeconds: 0,
            phashMinHamming: $phashMinHamming,
            maxPerStaypoint: null,
            relaxedMaxPerStaypoint: null,
            qualityFloor: 0.0,
            videoBonus: 0.0,
            faceBonus: 0.0,
            selfiePenalty: 0.0,
            phashPercentile: $phashPercentile,
        );
    }
}

