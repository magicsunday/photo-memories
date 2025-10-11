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
use MagicSunday\Memories\Service\Clusterer\Pipeline\DuplicateCollapseStage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DuplicateCollapseStageTest extends TestCase
{
    #[Test]
    public function keepsBestRepresentativePerFingerprint(): void
    {
        $stage = new DuplicateCollapseStage(['primary', 'secondary']);

        $metadata = ['bucket_key' => 'spring'];

        $primaryWinner   = $this->createDraft('primary', 0.8, [1, 2, 3], $metadata);
        $primaryLower    = $this->createDraft('primary', 0.5, [3, 1, 2], $metadata);
        $secondaryHigher = $this->createDraft('secondary', 0.9, [1, 2, 3], $metadata);
        $secondaryEqual  = $this->createDraft('secondary', 0.9, [3, 2, 1], $metadata);
        $unrelated       = $this->createDraft('primary', 0.7, [4, 5]);

        $result = $stage->process([
            $primaryWinner,
            $primaryLower,
            $secondaryHigher,
            $secondaryEqual,
            $unrelated,
        ]);

        self::assertCount(2, $result);
        self::assertTrue(in_array($unrelated, $result, true));
        self::assertTrue(
            in_array($secondaryHigher, $result, true)
            || in_array($secondaryEqual, $result, true),
        );
    }

    #[Test]
    public function preservesDistinctDraftsWhenMetadataDiffers(): void
    {
        $stage = new DuplicateCollapseStage(['primary']);

        $shared = [
            'member_selection' => [
                'hash_samples' => [1 => 'abcd1234abcd1234'],
            ],
            'scene_tags' => [
                ['label' => 'Beach', 'score' => 0.9],
            ],
        ];

        $first  = $this->createDraft('primary', 0.6, [7, 8, 9], ['bucket_key' => 'summer'] + $shared);
        $second = $this->createDraft('primary', 0.6, [9, 8, 7], ['bucket_key' => 'winter'] + $shared);

        $result = $stage->process([$first, $second]);

        self::assertCount(2, $result);
        self::assertTrue(in_array($first, $result, true));
        self::assertTrue(in_array($second, $result, true));
    }

    #[Test]
    public function prefersHigherAverageQualityBeforeScore(): void
    {
        $stage = new DuplicateCollapseStage(['primary']);

        $metadata = ['bucket_key' => 'autumn'];

        $highQuality = $this->createDraft('primary', 0.2, [5, 6, 7], $metadata + ['quality_avg' => 0.9]);
        $highScore   = $this->createDraft('primary', 0.9, [7, 6, 5], $metadata + ['quality_avg' => 0.6]);

        $result = $stage->process([$highQuality, $highScore]);

        self::assertCount(1, $result);
        self::assertSame($highQuality, $result[0]);
    }

    #[Test]
    public function prefersMoreFacesWhenQualityIsEqual(): void
    {
        $stage = new DuplicateCollapseStage(['primary']);

        $metadata = ['bucket_key' => 'festival', 'quality_avg' => 0.8];

        $manyFaces = $this->createDraft(
            'primary',
            0.3,
            [11, 12, 13],
            $metadata + ['faces_count' => 6],
        );

        $fewFaces = $this->createDraft(
            'primary',
            0.9,
            [13, 11, 12],
            $metadata + ['faces_count' => 1],
        );

        $result = $stage->process([$manyFaces, $fewFaces]);

        self::assertCount(1, $result);
        self::assertSame($manyFaces, $result[0]);
    }

    /**
     * @param list<int> $members
     */
    private function createDraft(string $algorithm, float $score, array $members, array $extraParams = []): ClusterDraft
    {
        $params = ['score' => $score];
        foreach ($extraParams as $key => $value) {
            $params[$key] = $value;
        }

        return new ClusterDraft(
            algorithm: $algorithm,
            params: $params,
            centroid: ['lat' => 0.0, 'lon' => 0.0],
            members: $members,
        );
    }
}
