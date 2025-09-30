<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Exif\Value;

/**
 * Represents normalized GPS information extracted from EXIF data.
 */
final readonly class GpsMetadata
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?float $altitude,
        public ?float $speedMetersPerSecond,
        public ?float $courseDegrees,
    ) {
    }
}
