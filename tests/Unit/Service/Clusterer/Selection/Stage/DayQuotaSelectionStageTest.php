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
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\DayQuotaSelectionStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;

final class DayQuotaSelectionStageTest extends TestCase
{
    #[Test]
    public function itPrefersAlternativeSignatureWhenQuotaAllows(): void
    {
        $stage     = new DayQuotaSelectionStage();
        $policy    = $this->createPolicy(['2024-06-01' => 3]);
        $telemetry = new SelectionTelemetry();

        $candidates = [
            ['id' => 1, 'day' => '2024-06-01', 'person_ids' => [11]],
            ['id' => 2, 'day' => '2024-06-01', 'person_ids' => [11]],
            ['id' => 3, 'day' => '2024-06-01', 'person_ids' => [11]],
            ['id' => 4, 'day' => '2024-06-01', 'person_ids' => [22]],
        ];

        $result = $stage->apply($candidates, $policy, $telemetry);

        self::assertSame([1, 2, 4], array_column($result, 'id'));
    }

    #[Test]
    public function itAcceptsRepeatedSignatureWhenNoAlternativeExists(): void
    {
        $stage     = new DayQuotaSelectionStage();
        $policy    = $this->createPolicy(['2024-06-01' => 3]);
        $telemetry = new SelectionTelemetry();

        $candidates = [
            ['id' => 1, 'day' => '2024-06-01', 'person_ids' => [7]],
            ['id' => 2, 'day' => '2024-06-01', 'person_ids' => [7]],
            ['id' => 3, 'day' => '2024-06-01', 'person_ids' => [7]],
        ];

        $result = $stage->apply($candidates, $policy, $telemetry);

        self::assertSame([1, 2, 3], array_column($result, 'id'));
    }

    /**
     * @param array<string, int> $dayQuotas
     */
    private function createPolicy(array $dayQuotas): SelectionPolicy
    {
        return new SelectionPolicy(
            profileKey: 'test',
            targetTotal: 10,
            minimumTotal: 5,
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
            dayQuotas: $dayQuotas,
        );
    }
}
