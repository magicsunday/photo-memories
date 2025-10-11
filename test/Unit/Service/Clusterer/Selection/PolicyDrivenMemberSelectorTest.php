<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Selection;

use MagicSunday\Memories\Service\Clusterer\Selection\PolicyDrivenMemberSelector;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicy;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

final class PolicyDrivenMemberSelectorTest extends TestCase
{
    #[Test]
    public function replacingNearDuplicateKeepsCapsAccurate(): void
    {
        $selector = new PolicyDrivenMemberSelector();

        $policy = new SelectionPolicy(
            'unit-test',
            3,
            1,
            2,
            1.0,
            0,
            5,
            2,
            0.0,
            0.0,
            0.0,
            0.0,
        );

        $baseHash = array_fill(0, 16, 1);
        $nearDuplicateHash = $baseHash;
        $nearDuplicateHash[15] = 0;
        $distinctHash = array_fill(0, 16, 0);

        $candidates = [
            [
                'id' => 1,
                'score' => 0.6,
                'is_video' => false,
                'persons' => ['Alice'],
                'day' => '2024-05-01',
                'timestamp' => 1714545600,
                'slot' => 2,
                'staypoint' => 42,
                'hash_bits' => $baseHash,
            ],
            [
                'id' => 2,
                'score' => 0.9,
                'is_video' => false,
                'persons' => ['Alice'],
                'day' => '2024-05-01',
                'timestamp' => 1714549200,
                'slot' => 2,
                'staypoint' => 42,
                'hash_bits' => $nearDuplicateHash,
            ],
            [
                'id' => 3,
                'score' => 0.7,
                'is_video' => false,
                'persons' => ['Bob'],
                'day' => '2024-05-01',
                'timestamp' => 1714552800,
                'slot' => 2,
                'staypoint' => 42,
                'hash_bits' => $distinctHash,
            ],
        ];

        $telemetry = [
            'drops' => [
                'slot' => 0,
                'spacing' => 0,
                'near_duplicate' => 0,
                'staypoint' => 0,
            ],
        ];

        $method = new ReflectionMethod(PolicyDrivenMemberSelector::class, 'runGreedy');
        $method->setAccessible(true);

        $result = $method->invokeArgs($selector, [$candidates, $policy, &$telemetry]);

        self::assertSame([2, 3], array_column($result, 'id'));
        self::assertSame(1, $telemetry['drops']['near_duplicate']);
        self::assertSame(0, $telemetry['drops']['slot']);
        self::assertSame(0, $telemetry['drops']['staypoint']);
    }
}
