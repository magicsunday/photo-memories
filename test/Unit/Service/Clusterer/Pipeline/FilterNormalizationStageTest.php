<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Pipeline;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Pipeline\FilterNormalizationStage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FilterNormalizationStageTest extends TestCase
{
    #[Test]
    public function filtersByScoreSizeAndTimeRange(): void
    {
        $stage = new FilterNormalizationStage(
            minScore: 0.5,
            minSize: 2,
            requireValidTime: true,
            minValidYear: 2000,
        );

        $validTime  = (new DateTimeImmutable('2005-01-01'))->getTimestamp();
        $validDraft = $this->createDraft(
            'primary',
            0.6,
            [1, 2, 3],
            ['time_range' => ['from' => $validTime, 'to' => $validTime + 10]],
        );
        $tooSmall = $this->createDraft(
            'primary',
            0.8,
            [4],
            ['time_range' => ['from' => $validTime, 'to' => $validTime + 10]],
        );
        $tooLowScore = $this->createDraft(
            'primary',
            0.3,
            [5, 6],
            ['time_range' => ['from' => $validTime, 'to' => $validTime + 10]],
        );
        $invalidTime = $this->createDraft(
            'primary',
            0.7,
            [7, 8],
            ['time_range' => ['from' => 0, 'to' => 0]],
        );
        $missingTime = $this->createDraft('primary', 0.7, [9, 10]);

        $result = $stage->process([
            $validDraft,
            $tooSmall,
            $tooLowScore,
            $invalidTime,
            $missingTime,
        ]);

        self::assertSame([
            $validDraft,
        ], $result);
    }

    /**
     * @param list<int>           $members
     * @param array<string,mixed> $params
     */
    private function createDraft(string $algorithm, float $score, array $members, array $params = []): ClusterDraft
    {
        $params['score'] ??= $score;

        return new ClusterDraft(
            algorithm: $algorithm,
            params: $params,
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: $members,
        );
    }
}
