<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Support;

/**
 * Contract for runtime feature toggle lookups.
 */
interface FeatureFlagProviderInterface
{
    /**
     * Determines whether the given feature flag is enabled.
     */
    public function isEnabled(string $flag): bool;
}
