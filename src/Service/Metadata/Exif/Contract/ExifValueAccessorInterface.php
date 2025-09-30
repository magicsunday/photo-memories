<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Exif\Contract;

use DateTimeImmutable;
use MagicSunday\Memories\Service\Metadata\Exif\Value\GpsMetadata;

/**
 * Provides strongly typed accessors for common EXIF value conversions.
 */
interface ExifValueAccessorInterface
{
    /**
     * Finds the most suitable capture date within the EXIF data.
     *
     * @param array<string,mixed> $exif
     */
    public function findDate(array $exif): ?DateTimeImmutable;

    /**
     * Parses the timezone offset in minutes from the EXIF data.
     *
     * @param array<string,mixed> $exif
     */
    public function parseOffsetMinutes(array $exif): ?int;

    public function intOrNull(mixed $value): ?int;

    public function intFromScalarOrArray(mixed $value): ?int;

    public function floatOrRational(mixed $value): ?float;

    public function exposureToSeconds(mixed $value): ?float;

    public function strOrNull(mixed $value): ?string;

    /**
     * Converts GPS EXIF data into decimal degrees/metric values.
     *
     * @param array<string,mixed> $gps
     */
    public function gpsFromExif(array $gps): ?GpsMetadata;
}
