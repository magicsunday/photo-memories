<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

/**
 * Tracks runtime availability of the configured face-detection backend.
 */
final class FaceDetectionAvailability
{
    private bool $available = true;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function markAvailable(): void
    {
        $this->available = true;
    }

    public function markUnavailable(): void
    {
        $this->available = false;
    }
}
