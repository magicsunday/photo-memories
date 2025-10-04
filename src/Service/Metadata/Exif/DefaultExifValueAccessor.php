<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Exif;

use DateTimeImmutable;
use MagicSunday\Memories\Service\Metadata\Exif\Contract\ExifValueAccessorInterface;
use MagicSunday\Memories\Service\Metadata\Exif\Value\GpsMetadata;
use Throwable;

use function array_pad;
use function explode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function str_contains;
use function strlen;
use function strtoupper;
use function strtr;
use function substr;

/**
 * Converts raw EXIF values into typed representations.
 */
final class DefaultExifValueAccessor implements ExifValueAccessorInterface
{
    /** Map PHP's "UndefinedTag" keys to their EXIF 2.31 names. */
    private const array OFFSET_ALIASES = [
        'UndefinedTag:0x001F' => 'GPSHPositioningError',
        'UndefinedTag:0xA430' => 'CameraOwnerName',
        'UndefinedTag:0xA431' => 'BodySerialNumber',
        'UndefinedTag:0xA432' => 'LensSpecification',
        'UndefinedTag:0xA433' => 'LensMake',
        'UndefinedTag:0xA434' => 'LensModel',
        'UndefinedTag:0xA435' => 'LensSerialNumber',
        'UndefinedTag:0x9010' => 'OffsetTime',
        'UndefinedTag:0x9011' => 'OffsetTimeOriginal',
        'UndefinedTag:0x9012' => 'OffsetTimeDigitized',
        'UndefinedTag:0x882A' => 'TimeZoneOffset', // fallback: some cams use 0x882A
        'UndefinedTag:0xA460' => 'Gamma',
        'UndefinedTag:0xA461' => 'CompositeImage',
        'UndefinedTag:0xA462' => 'SourceImageNumberOfCompositeImage',
        'UndefinedTag:0xA463' => 'SourceExposureTimesOfCompositeImage',
    ];

    /**
     * Recursively traverses the full EXIF array and, at every nesting level,
     * adds friendly keys (OffsetTime*, TimeZoneOffset) next to any "UndefinedTag:*".
     *
     * @param array<string, mixed> $exif              Full EXIF array (with EXIF/IFD0/... sub-arrays)
     * @param bool                 $preserveOriginal  If false, removes the original "UndefinedTag:*" keys
     *
     * @return array<string, mixed> Normalized EXIF array
     */
    public static function normalizeKeys(array $exif, bool $preserveOriginal = true): array
    {
        return self::normalizeNode($exif, $preserveOriginal);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function normalizeNode(array $node, bool $preserveOriginal): array
    {
        // 1) Recurse into children first
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $node[$key] = self::normalizeNode($value, $preserveOriginal);
            }
        }

        // 2) Add aliases at this level (do not overwrite existing "nice" keys)
        foreach (self::OFFSET_ALIASES as $ugly => $nice) {
            if (array_key_exists($ugly, $node) && !array_key_exists($nice, $node)) {
                $node[$nice] = $node[$ugly];
            }
        }

        // 3) Optionally drop the original "UndefinedTag:*" keys
        if ($preserveOriginal === false) {
            foreach (self::OFFSET_ALIASES as $ugly => $nice) {
                if (array_key_exists($ugly, $node)) {
                    unset($node[$ugly]);
                }
            }
        }

        return $node;
    }

    public function findDate(array $exif): ?DateTimeImmutable
    {
        $candidates = [
            $exif['EXIF']['DateTimeOriginal'] ?? null,
            $exif['EXIF']['DateTimeDigitized'] ?? null,
            $exif['IFD0']['DateTime'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $normalized = substr($candidate, 0, 19);
                if ($this->looksLikeExifDate($normalized)) {
                    $value = $this->normalizeExifDate($normalized);
                    try {
                        return new DateTimeImmutable($value);
                    } catch (Throwable) {
                        // continue, the fallback below may still succeed
                    }
                }

                try {
                    return new DateTimeImmutable($candidate);
                } catch (Throwable) {
                    // try next candidate
                }
            }
        }

        return null;
    }

    public function parseOffsetMinutes(array $exif): ?int
    {
        $exif = self::normalizeKeys($exif);

        $candidates = [
            $exif['EXIF']['OffsetTimeOriginal'] ?? null,
            $exif['EXIF']['OffsetTimeDigitized'] ?? null,
            $exif['EXIF']['OffsetTime'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate, " \t\n\r\0\x0B");
            if ($normalized === '') {
                continue;
            }

            if ($normalized === 'Z') {
                return 0;
            }

            if (preg_match('~^([+-]?)(\d{2})(?::?(\d{2}))(?::?(\d{2}))?$~', $normalized, $matches) !== 1) {
                continue;
            }

            $sign    = $matches[1] === '-' ? -1 : 1;
            $hours   = (int) $matches[2];
            $minutes = isset($matches[3]) ? (int) $matches[3] : 0;

            if (isset($matches[4]) && $matches[4] !== '00') {
                continue;
            }

            return $sign * ($hours * 60 + $minutes);
        }

        return null;
    }

    public function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return (int) $value;
        }

        return null;
    }

    public function intFromScalarOrArray(mixed $value): ?int
    {
        $int = $this->intOrNull($value);
        if ($int !== null) {
            return $int;
        }

        if (is_array($value) && isset($value[0])) {
            return $this->intOrNull($value[0]);
        }

        return null;
    }

    public function floatOrRational(mixed $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && $value !== '') {
            if (str_contains($value, '/')) {
                [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, '1');
                $denominatorValue          = (float) $denominator;

                return $denominatorValue !== 0.0 ? (float) $numerator / $denominatorValue : null;
            }

            return (float) $value;
        }

        return null;
    }

    public function exposureToSeconds(mixed $value): ?float
    {
        if (is_string($value) && str_contains($value, '/')) {
            [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, '1');
            $denominatorValue          = (float) $denominator;

            return $denominatorValue !== 0.0 ? (float) $numerator / $denominatorValue : null;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    public function strOrNull(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    public function gpsFromExif(array $gps): ?GpsMetadata
    {
        $latitude  = $this->coordinateToFloat($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? null);
        $longitude = $this->coordinateToFloat($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? null);
        if ($latitude === null || $longitude === null) {
            return null;
        }

        $altitude    = $this->floatOrRational($gps['GPSAltitude'] ?? null);
        $altitudeRef = $this->intOrNull($gps['GPSAltitudeRef'] ?? null);
        if ($altitude !== null && $altitudeRef === 1) {
            $altitude = -$altitude;
        }

        $speedValue = $this->floatOrRational($gps['GPSSpeed'] ?? null);
        $speed      = null;
        if ($speedValue !== null) {
            $reference = is_string($gps['GPSSpeedRef'] ?? null) ? $gps['GPSSpeedRef'] : 'K';
            $speed     = match (strtoupper($reference)) {
                'M'     => $speedValue * 0.44704,
                'N'     => $speedValue * 0.514444,
                default => $speedValue / 3.6,
            };
        }

        $course = $this->floatOrRational($gps['GPSTrack'] ?? null);

        return new GpsMetadata($latitude, $longitude, $altitude, $speed, $course);
    }

    private function looksLikeExifDate(string $value): bool
    {
        return strlen($value) === 19
            && $value[4] === ':'
            && $value[7] === ':'
            && $value[13] === ':'
            && $value[16] === ':';
    }

    private function normalizeExifDate(string $value): string
    {
        $normalized = strtr($value, [':' => '-']);
        $normalized[13] = ':';
        $normalized[16] = ':';

        return $normalized;
    }

    private function coordinateToFloat(mixed $value, ?string $reference): ?float
    {
        if (!is_array($value)) {
            return null;
        }

        $degrees = $this->floatOrRational($value[0] ?? null);
        $minutes = $this->floatOrRational($value[1] ?? null);
        $seconds = $this->floatOrRational($value[2] ?? null);
        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $sign = ($reference === 'S' || $reference === 'W') ? -1.0 : 1.0;

        return $sign * ($degrees + $minutes / 60.0 + $seconds / 3600.0);
    }
}
