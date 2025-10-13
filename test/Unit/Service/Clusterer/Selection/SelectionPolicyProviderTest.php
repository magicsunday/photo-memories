<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Selection;

use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicyProvider;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SelectionPolicyProviderTest extends TestCase
{
    #[Test]
    public function itAppliesRunLengthConstraintsAndToggles(): void
    {
        $profiles = [
            'vacation_weekend_transit' => [
                'target_total' => 36,
                'minimum_total' => 24,
                'max_per_day' => 4,
                'time_slot_hours' => 4.0,
                'min_spacing_seconds' => 3600,
                'phash_min_hamming' => 10,
                'max_per_staypoint' => 2,
                'max_per_staypoint_relaxed' => 3,
                'quality_floor' => 0.6,
                'video_bonus' => 0.3,
                'face_bonus' => 0.2,
                'selfie_penalty' => 0.1,
                'max_per_year' => null,
                'max_per_bucket' => null,
                'video_heavy_bonus' => null,
                'scene_bucket_weights' => null,
                'core_day_bonus' => 1,
                'peripheral_day_penalty' => 1,
                'phash_percentile' => 0.35,
                'spacing_progress_factor' => 0.5,
                'cohort_repeat_penalty' => 0.05,
            ],
        ];

        $constraints = [
            'vacation_weekend_transit' => [
                'target_total_by_run_length' => [
                    'short_run_max_days' => 2,
                    'short_run_target_total' => 18,
                    'medium_run_max_days' => 4,
                    'medium_run_target_total' => 24,
                    'long_run_target_total' => 36,
                ],
                'minimum_total_by_run_length' => [
                    'short_run_max_days' => 2,
                    'short_run_minimum_total' => 12,
                    'medium_run_max_days' => 4,
                    'medium_run_minimum_total' => 18,
                    'long_run_minimum_total' => 24,
                ],
                'enable_people_balance' => false,
                'people_balance_weight' => 0.25,
            ],
        ];

        $provider = new SelectionPolicyProvider(
            profiles: $profiles,
            defaultProfile: 'vacation_weekend_transit',
            algorithmProfiles: ['vacation' => 'vacation_weekend_transit'],
            profileConstraints: $constraints,
        );

        $short = $provider->forAlgorithmWithRunLength('vacation', null, 2);
        self::assertSame(18, $short->getTargetTotal());
        self::assertSame(12, $short->getMinimumTotal());
        self::assertSame(4, $short->getMaxPerDay());
        $shortMetadata = $short->getMetadata();
        self::assertSame(2, $shortMetadata['run_length_days']);
        self::assertArrayHasKey('constraint_overrides', $shortMetadata);
        self::assertSame(
            [
                'enable_people_balance' => false,
                'people_balance_weight' => 0.25,
                'target_total' => 18,
                'minimum_total' => 12,
            ],
            $shortMetadata['constraint_overrides'],
        );

        $medium = $provider->forAlgorithmWithRunLength('vacation', null, 4);
        self::assertSame(24, $medium->getTargetTotal());
        self::assertSame(18, $medium->getMinimumTotal());
        self::assertSame(4, $medium->getMaxPerDay());

        $long = $provider->forAlgorithmWithRunLength('vacation', null, 7);
        self::assertSame(36, $long->getTargetTotal());
        self::assertSame(24, $long->getMinimumTotal());
        self::assertSame(4, $long->getMaxPerDay());

        $default = $provider->forAlgorithm('vacation');
        self::assertSame(36, $default->getTargetTotal());
        self::assertSame(24, $default->getMinimumTotal());
        self::assertSame(4, $default->getMaxPerDay());
        $defaultMetadata = $default->getMetadata();
        self::assertArrayHasKey('constraint_overrides', $defaultMetadata);
        self::assertSame(
            [
                'enable_people_balance' => false,
                'people_balance_weight' => 0.25,
            ],
            $defaultMetadata['constraint_overrides'],
        );
    }
}
