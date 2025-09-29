<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use Throwable;

use function array_pad;
use function exif_read_data;
use function explode;
use function is_array;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function strtr;
use function substr;

/**
 * Extracts EXIF metadata from images (and optionally videos) and enriches Media.
 *
 * Notes:
 * - Idempotent: only sets fields it owns and only when values are available.
 * - Robust date parsing for "YYYY:MM:DD HH:MM:SS" and OffsetTime(Original).
 * - GPS: converts DMS to decimal; GPSSpeedRef K/M/N -> m/s; altitude sign via GPSAltitudeRef.
 * - Fallback for dimensions via EXIF COMPUTED if Media width/height are not set.
 */
final readonly class ExifMetadataExtractor implements SingleMetadataExtractorInterface
{
    public function __construct(
        private bool $readExifForVideos = false,
    ) {
    }

    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();
        if ($mime === null) {
            return false;
        }

        if (str_starts_with($mime, 'image/')) {
            return true;
        }

        return $this->readExifForVideos && str_starts_with($mime, 'video/');
    }

    public function extract(string $filepath, Media $media): Media
    {
        if (!is_file($filepath)) {
            // Not considered a fatal extraction error; simply no-op.
            return $media;
        }

        try {
            /** @var array<string,mixed>|false $exif */
            $exif = @exif_read_data($filepath, null, true, false);
        } catch (Throwable) {
            $exif = false;
        }

        if ($exif === false) {
            return $media;
        }

        // --- Date & Time ---
        $taken = $this->findDate($exif);
        if ($taken instanceof DateTimeImmutable) {
            $media->setTakenAt($taken);
        }

        $offset = $this->parseOffsetMinutes($exif);
        if ($offset !== null) {
            $media->setTimezoneOffsetMin($offset);
        }

        // --- Dimensions (fallback if missing) ---
        if ($media->getWidth() === null || $media->getHeight() === null) {
            $w = isset($exif['COMPUTED']['Width']) ? (int) $exif['COMPUTED']['Width'] : null;
            $h = isset($exif['COMPUTED']['Height']) ? (int) $exif['COMPUTED']['Height'] : null;
            if ($w !== null && $w > 0) {
                $media->setWidth($w);
            }

            if ($h !== null && $h > 0) {
                $media->setHeight($h);
            }
        }

        // --- Orientation ---
        $orient = $this->intOrNull($exif['IFD0']['Orientation'] ?? null);
        if ($orient !== null) {
            $media->setOrientation($orient);
        }

        // --- Camera / Lens ---
        $make  = $this->strOrNull($exif['IFD0']['Make'] ?? null);
        $model = $this->strOrNull($exif['IFD0']['Model'] ?? null);
        $lens  = $this->strOrNull(
            $exif['EXIF']['UndefinedTag:0xA434'] ?? ($exif['EXIF']['LensModel'] ?? null)
        );

        if ($make !== null) {
            $media->setCameraMake($make);
        }

        if ($model !== null) {
            $media->setCameraModel($model);
        }

        if ($lens !== null) {
            $media->setLensModel($lens);
        }

        $focalMm   = $this->floatOrRational($exif['EXIF']['FocalLength'] ?? null);
        $focal35   = $this->intOrNull($exif['EXIF']['FocalLengthIn35mmFilm'] ?? null);
        $fNumber   = $this->floatOrRational($exif['EXIF']['FNumber'] ?? null);
        $exposureS = $this->exposureToSeconds($exif['EXIF']['ExposureTime'] ?? null);
        $iso       = $this->intFromScalarOrArray($exif['EXIF']['ISOSpeedRatings'] ?? ($exif['EXIF']['PhotographicSensitivity'] ?? null));
        $flash     = $this->intOrNull($exif['EXIF']['Flash'] ?? null);

        if ($focalMm !== null) {
            $media->setFocalLengthMm($focalMm);
        }

        if ($focal35 !== null) {
            $media->setFocalLength35mm($focal35);
        }

        if ($fNumber !== null) {
            $media->setApertureF($fNumber);
        }

        if ($exposureS !== null) {
            $media->setExposureTimeS($exposureS);
        }

        if ($iso !== null) {
            $media->setIso($iso);
        }

        if ($flash !== null) {
            $media->setFlashFired(($flash & 1) === 1);
        }

        // --- SubSec (fine ordering) ---
        $subsec = $this->intOrNull($exif['EXIF']['SubSecTimeOriginal'] ?? null);
        if ($subsec !== null) {
            $media->setSubSecOriginal($subsec);
        }

        // --- GPS ---
        if (isset($exif['GPS']) && is_array($exif['GPS'])) {
            $gps = $this->gpsFromExif($exif['GPS']);
            if ($gps !== null) {
                $media->setGpsLat($gps['lat']);
                $media->setGpsLon($gps['lon']);
                if ($gps['alt'] !== null) {
                    $media->setGpsAlt($gps['alt']);
                }

                if ($gps['speed'] !== null) {
                    $media->setGpsSpeedMps($gps['speed']);
                }

                if ($gps['course'] !== null) {
                    $media->setGpsHeadingDeg($gps['course']);
                }
            }
        }

        // --- Aspect flags (Portrait/Panorama) ---
        $wM = $media->getWidth();
        $hM = $media->getHeight();
        if ($wM !== null && $hM !== null && $wM > 0 && $hM > 0) {
            if ($hM > $wM && ((float) $hM / (float) $wM) >= 1.2) {
                $media->setIsPortrait(true);
            }

            if ($wM > $hM && ((float) $wM / (float) $hM) >= 2.4) {
                $media->setIsPanorama(true);
            }
        }

        return $media;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function findDate(array $exif): ?DateTimeImmutable
    {
        $candidates = [
            $exif['EXIF']['DateTimeOriginal'] ?? null,
            $exif['IFD0']['DateTime'] ?? null,
            $exif['EXIF']['DateTimeDigitized'] ?? null,
        ];

        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                // Typical EXIF format: "YYYY:MM:DD HH:MM:SS"
                $s = substr($c, 0, 19);
                if (strlen($s) === 19 && $s[4] === ':' && $s[7] === ':' && $s[13] === ':' && $s[16] === ':') {
                    $norm = strtr($s, [':' => '-']); // becomes "YYYY-MM-DD HH-MM-SS"
                    // restore time separators
                    $norm[13] = ':';
                    $norm[16] = ':';
                    try {
                        return new DateTimeImmutable($norm);
                    } catch (Throwable) {
                        // continue with fallback below
                    }
                }

                try {
                    // Fallback: feed original as-is
                    return new DateTimeImmutable($c);
                } catch (Throwable) {
                    // try next candidate
                }
            }
        }

        return null;
    }

    private function parseOffsetMinutes(array $exif): ?int
    {
        $off = $exif['EXIF']['OffsetTimeOriginal'] ?? ($exif['EXIF']['OffsetTime'] ?? null);
        if (!is_string($off)) {
            return null;
        }

        // Match "+02:00" or "+0200"
        if (preg_match('~^([+-])(\d{2}):?(\d{2})$~', $off, $m) === 1) {
            $sign = $m[1] === '-' ? -1 : 1;
            $h    = (int) $m[2];
            $mn   = (int) $m[3];

            return $sign * ($h * 60 + $mn);
        }

        return null;
    }

    /** @param mixed $v */
    private function intOrNull($v): ?int
    {
        if (is_int($v)) {
            return $v;
        }

        if (is_string($v) && $v !== '') {
            return (int) $v;
        }

        return null;
    }

    /** @param mixed $v */
    private function intFromScalarOrArray($v): ?int
    {
        if (is_int($v)) {
            return $v;
        }

        if (is_string($v) && $v !== '') {
            return (int) $v;
        }

        if (is_array($v) && isset($v[0])) {
            $first = $v[0];
            if (is_int($first)) {
                return $first;
            }

            if (is_string($first) && $first !== '') {
                return (int) $first;
            }
        }

        return null;
    }

    /** @param mixed $v */
    private function floatOrRational($v): ?float
    {
        if (is_float($v)) {
            return $v;
        }

        if (is_int($v)) {
            return (float) $v;
        }

        if (is_string($v) && $v !== '') {
            if (str_contains($v, '/')) {
                [$a, $b] = array_pad(explode('/', $v, 2), 2, '1');
                $bn      = (float) $b;

                return $bn !== 0.0 ? (float) $a / $bn : null;
            }

            return (float) $v;
        }

        return null;
    }

    /** @param mixed $v */
    private function exposureToSeconds($v): ?float
    {
        if (is_string($v) && str_contains($v, '/')) {
            [$a, $b] = array_pad(explode('/', $v, 2), 2, '1');
            $bn      = (float) $b;

            return $bn !== 0.0 ? (float) $a / $bn : null;
        }

        if (is_float($v)) {
            return $v;
        }

        if (is_int($v)) {
            return (float) $v;
        }

        return null;
    }

    private function strOrNull(mixed $v): ?string
    {
        if (is_string($v) && $v !== '') {
            return $v;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $gps
     *
     * @return array{lat: float, lon: float, alt: ?float, speed: ?float, course: ?float}|null
     */
    private function gpsFromExif(array $gps): ?array
    {
        $lat = $this->coordToFloat($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? null);
        $lon = $this->coordToFloat($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? null);
        if ($lat === null || $lon === null) {
            return null;
        }

        // Altitude with reference: 0=above sea level, 1=below
        $alt    = $this->floatOrRational($gps['GPSAltitude'] ?? null);
        $altRef = $this->intOrNull($gps['GPSAltitudeRef'] ?? null);
        if ($alt !== null && $altRef === 1) {
            $alt = -$alt;
        }

        // Speed with unit conversion to m/s
        $speed   = $this->floatOrRational($gps['GPSSpeed'] ?? null);
        $speedMs = null;
        if ($speed !== null) {
            $ref     = is_string($gps['GPSSpeedRef'] ?? null) ? $gps['GPSSpeedRef'] : 'K';
            $speedMs = match (strtoupper($ref)) {
                'M'     => $speed * 0.44704,   // mph -> m/s
                'N'     => $speed * 0.514444, // knots -> m/s
                default => $speed / 3.6,  // 'K' km/h -> m/s
            };
        }

        // Track (heading / course) in degrees; reference ignored (T/M)
        $course = $this->floatOrRational($gps['GPSTrack'] ?? null);

        return ['lat' => $lat, 'lon' => $lon, 'alt' => $alt, 'speed' => $speedMs, 'course' => $course];
    }

    /**
     * @param mixed       $val EXIF GPS coordinate array with rationals
     * @param string|null $ref 'N'/'S' or 'E'/'W'
     */
    private function coordToFloat(mixed $val, ?string $ref): ?float
    {
        if (!is_array($val)) {
            return null;
        }

        $deg = $this->floatOrRational($val[0] ?? null);
        $min = $this->floatOrRational($val[1] ?? null);
        $sec = $this->floatOrRational($val[2] ?? null);
        if ($deg === null || $min === null || $sec === null) {
            return null;
        }

        $sign = ($ref === 'S' || $ref === 'W') ? -1.0 : 1.0;

        return $sign * ($deg + $min / 60.0 + $sec / 3600.0);
    }
}
