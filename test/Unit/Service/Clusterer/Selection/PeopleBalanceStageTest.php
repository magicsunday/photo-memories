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
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;
use MagicSunday\Memories\Service\Clusterer\Selection\Stage\PeopleBalanceStage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PeopleBalanceStageTest extends TestCase
{
    #[Test]
    public function enforcesNeutralShareWhenNoImportantIds(): void
    {
        $stage     = new PeopleBalanceStage();
        $policy    = $this->makePolicy();
        $telemetry = new SelectionTelemetry();

        $candidates = [
            $this->candidate(1, [1]),
            $this->candidate(2, [2]),
            $this->candidate(3, [1]),
            $this->candidate(4, [3]),
            $this->candidate(5, [1]),
        ];

        $selected = $stage->apply($candidates, $policy, $telemetry);

        self::assertSame([1, 2, 4], array_map(static fn (array $item): int => $item['id'], $selected));
    }

    #[Test]
    public function allowsGroupFramesBeyondNeutralShare(): void
    {
        $stage     = new PeopleBalanceStage();
        $policy    = $this->makePolicy();
        $telemetry = new SelectionTelemetry();

        $candidates = [
            $this->candidate(1, [1]),
            $this->candidate(2, [2]),
            $this->candidate(3, [1], ['count' => 4, 'largest_coverage' => 0.35]),
            $this->candidate(4, [3]),
        ];

        $selected = $stage->apply($candidates, $policy, $telemetry);

        self::assertSame([1, 2, 3, 4], array_map(static fn (array $item): int => $item['id'], $selected));
    }

    #[Test]
    public function keepsFiftyPercentCeilingWhenImportantPersonPresent(): void
    {
        $stage     = new PeopleBalanceStage([99]);
        $policy    = $this->makePolicy();
        $telemetry = new SelectionTelemetry();

        $candidates = [
            $this->candidate(1, [99]),
            $this->candidate(2, [1]),
            $this->candidate(3, [2]),
            $this->candidate(4, [1]),
        ];

        $selected = $stage->apply($candidates, $policy, $telemetry);
        $ids      = array_map(static fn (array $item): int => $item['id'], $selected);

        self::assertSame([1, 2, 3, 4], $ids);
    }

    private function makePolicy(): SelectionPolicy
    {
        return new SelectionPolicy(
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
        );
    }

    /**
     * @param list<int> $personIds
     * @param array<string, mixed> $faceMetrics
     *
     * @return array<string, mixed>
     */
    private function candidate(int $id, array $personIds, array $faceMetrics = []): array
    {
        return [
            'id'            => $id,
            'person_ids'    => $personIds,
            'face_metrics'  => [
                'count'            => (int) ($faceMetrics['count'] ?? 0),
                'largest_coverage' => $faceMetrics['largest_coverage'] ?? null,
            ],
            'has_faces'     => ((int) ($faceMetrics['count'] ?? 0)) > 0,
        ];
    }
}
