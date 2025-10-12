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
    public function retainsNestedClustersAndAttachesMetadata(): void
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
            $placeEvent,
            $dayTrip,
            $vacation,
        ], $result);

        $vacationParams = $vacation->getParams();
        self::assertTrue($vacationParams['has_sub_stories'] ?? false);

        $expectedChapters = [
            [
                'algorithm'    => 'vacation',
                'priority'     => 2,
                'score'        => 0.65,
                'member_count' => 2,
                'fingerprint'  => sha1('2,3'),
                'classification' => 'day_trip',
            ],
            [
                'algorithm'    => 'significant_place',
                'priority'     => 1,
                'score'        => 0.55,
                'member_count' => 3,
                'fingerprint'  => sha1('2,3,4'),
            ],
        ];

        self::assertSame($expectedChapters, $vacationParams['sub_stories']);
        self::assertTrue($dayTrip->getParams()['is_sub_story']);
        self::assertTrue($placeEvent->getParams()['is_sub_story']);
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
