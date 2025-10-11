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
use MagicSunday\Memories\Service\Clusterer\Pipeline\NestingResolverStage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class NestingResolverStageTest extends TestCase
{
    #[Test]
    public function dropsNestedClustersBasedOnPriority(): void
    {
        $stage = new NestingResolverStage(['vacation', 'significant_place']);

        $vacation   = $this->createDraft('vacation', [1, 2, 3, 4], 0.92, 'vacation');
        $dayTrip    = $this->createDraft('vacation', [2, 3], 0.65, 'day_trip');
        $placeEvent = $this->createDraft('significant_place', [2, 3, 4], 0.55, null);

        $result = $stage->process([
            $placeEvent,
            $dayTrip,
            $vacation,
        ]);

        self::assertSame([
            $vacation,
        ], $result);
    }

    #[Test]
    public function keepsDisjointClusters(): void
    {
        $stage = new NestingResolverStage(['vacation', 'significant_place']);

        $vacation = $this->createDraft('vacation', [1, 2, 3, 4], 0.92, 'vacation');
        $cityTrip = $this->createDraft('significant_place', [10, 11, 12], 0.6, null);

        $result = $stage->process([
            $vacation,
            $cityTrip,
        ]);

        self::assertSame([
            $vacation,
            $cityTrip,
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
