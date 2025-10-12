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
use MagicSunday\Memories\Service\Clusterer\Pipeline\OverlapResolverStage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OverlapResolverStageTest extends TestCase
{
    #[Test]
    public function removesHighOverlapWithinSameAlgorithm(): void
    {
        $stage = new OverlapResolverStage(0.5, 0.8, ['vacation', 'hike_adventure']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4], 0.92, 'vacation');
        $dayTrip  = $this->createDraft('vacation', [1, 2, 3], 0.6, 'day_trip');
        $hike     = $this->createDraft('hike_adventure', [5, 6, 7], 0.7, null);
        $city     = $this->createDraft('significant_place', [8, 9], 0.5, null);

        $result = $stage->process([
            $vacation,
            $dayTrip,
            $hike,
            $city,
        ]);

        self::assertSame([
            $vacation,
            $hike,
            $city,
        ], $result);
    }

    #[Test]
    public function dropsLowerPriorityOnSevereCrossAlgorithmOverlap(): void
    {
        $stage = new OverlapResolverStage(0.5, 0.8, ['vacation', 'hike_adventure']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4, 6], 0.9, 'vacation');
        $hike     = $this->createDraft('hike_adventure', [1, 2, 3, 4, 5, 6], 0.7, null);

        $result = $stage->process([
            $hike,
            $vacation,
        ]);

        self::assertSame([
            $vacation,
        ], $result);
    }

    #[Test]
    public function ignoresSubStoriesDuringOverlapResolution(): void
    {
        $stage = new OverlapResolverStage(0.5, 0.8, ['vacation', 'significant_place']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4], 0.92, 'vacation');
        $chapter  = $this->createDraft('significant_place', [1, 2, 3], 0.6, null);
        $chapter->setParam('is_sub_story', true);
        $chapter->setParam('sub_story_priority', 1);
        $chapter->setParam('sub_story_of', ['algorithm' => 'vacation', 'fingerprint' => sha1('1,2,3,4'), 'priority' => 2]);

        $result = $stage->process([
            $vacation,
            $chapter,
        ]);

        self::assertSame([
            $vacation,
            $chapter,
        ], $result);
    }

    /**
     * @param list<int> $members
     */
    private function createDraft(string $algorithm, array $members, float $score, ?string $classification): ClusterDraft
    {
        $params = ['score' => $score];
        if ($classification !== null) {
            $params['classification'] = $classification;
        }

        return new ClusterDraft(
            algorithm: $algorithm,
            params: $params,
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: $members,
        );
    }
}
