<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Support;

use MagicSunday\Memories\Entity\Cluster;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ClusterEntityToDraftMapperTest extends TestCase
{
    #[Test]
    public function mapManyBackfillsMissingGroup(): void
    {
        $entity = new Cluster(
            algorithm: 'vacation',
            params: ['score' => 0.9],
            centroid: ['lat' => 1.0, 'lon' => 2.0],
            members: [3, 1, 2],
        );

        $mapper = new ClusterEntityToDraftMapper(['vacation' => 'travel_and_places']);

        $drafts = $mapper->mapMany([$entity]);

        self::assertCount(1, $drafts);
        $params = $drafts[0]->getParams();

        self::assertSame('travel_and_places', $params['group']);
    }

    #[Test]
    public function mapManyKeepsExistingGroup(): void
    {
        $entity = new Cluster(
            algorithm: 'vacation',
            params: ['score' => 0.9, 'group' => 'custom_group'],
            centroid: ['lat' => 1.0, 'lon' => 2.0],
            members: [1, 2, 3],
        );

        $mapper = new ClusterEntityToDraftMapper(['vacation' => 'travel_and_places']);

        $drafts = $mapper->mapMany([$entity]);

        self::assertSame('custom_group', $drafts[0]->getParams()['group']);
    }
}
