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
 * Represents cached sunrise and sunset information for a coordinate/day pair.
 */
final readonly class SolarEventResult
{
    public function __construct(
        public ?int $sunriseUtc,
        public ?int $sunsetUtc,
        public bool $isPolarDay,
        public bool $isPolarNight,
    ) {
    }

    public function isRegularDay(): bool
    {
        return !$this->isPolarDay && !$this->isPolarNight;
    }
}
