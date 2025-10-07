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

    #[Test]
    public function mapManyPreservesExtendedParameters(): void
    {
        $movement = [
            'segment_count'                             => 5,
            'fast_segment_count'                        => 3,
            'fast_segment_ratio'                        => 0.4,
            'speed_sample_count'                        => 4,
            'avg_speed_mps'                             => 8.1,
            'max_speed_mps'                             => 12.6,
            'heading_sample_count'                      => 3,
            'avg_heading_change_deg'                    => 35.0,
            'consistent_heading_segment_count'          => 2,
            'heading_consistency_ratio'                 => 0.66,
            'fast_segment_speed_threshold_mps'          => 5.0,
            'min_fast_segment_count_threshold'          => 2,
            'max_heading_change_threshold_deg'          => 90.0,
            'min_consistent_heading_segments_threshold' => 1,
        ];

        $params = [
            'score'                => 0.95,
            'group'                => 'travel_and_places',
            'quality_avg'          => 0.82,
            'quality_resolution'   => 0.91,
            'people'               => 0.5,
            'people_count'         => 6,
            'people_unique'        => 3,
            'people_coverage'      => 0.75,
            'people_face_coverage' => 0.5,
            'movement'             => $movement,
        ];

        $entity = new Cluster(
            algorithm: 'vacation',
            params: $params,
            centroid: ['lat' => 1.0, 'lon' => 2.0],
            members: [3, 1, 2],
        );

        $mapper = new ClusterEntityToDraftMapper(['vacation' => 'travel_and_places']);

        $drafts = $mapper->mapMany([$entity]);

        self::assertCount(1, $drafts);

        $draftParams = $drafts[0]->getParams();

        self::assertSame(0.82, $draftParams['quality_avg']);
        self::assertSame(0.91, $draftParams['quality_resolution']);
        self::assertSame(0.5, $draftParams['people']);
        self::assertSame(6, $draftParams['people_count']);
        self::assertSame(3, $draftParams['people_unique']);
        self::assertSame(0.75, $draftParams['people_coverage']);
        self::assertSame(0.5, $draftParams['people_face_coverage']);
        self::assertSame($movement, $draftParams['movement']);
    }
}
