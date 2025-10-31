<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection\Value;

/**
 * Captures derived runtime values shared across selection stages.
 */
final readonly class Derived
{
    /**
     * @param list<string> $uniqueDays
     * @param array<string, int> $quotaSpacingSeconds
     */
    public function __construct(
        public int $runDays,
        public int $defaultPerDayCap,
        public array $uniqueDays,
        public array $quotaSpacingSeconds,
    ) {
    }
}
