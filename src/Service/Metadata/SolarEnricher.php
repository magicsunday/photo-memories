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
use MagicSunday\Memories\Service\Metadata\Support\CaptureTimeResolver;
use MagicSunday\Memories\Service\Metadata\Support\SolarEventCache;
use MagicSunday\Memories\Service\Metadata\Support\SolarEventResult;

use function acos;
use function asin;
use function assert;
use function cos;
use function deg2rad;
use function floor;
use function fmod;
use function rad2deg;
use function round;
use function sin;
use function sprintf;

/**
 * Rough sunrise/sunset and golden-hour flags without requiring php-calendar.
 *
 * - Uses Meeus-like approximation.
 * - Converts Julian day directly to Unix time (UTC) via JD 2440587.5 anchor.
 * - Compares in "local wall time" using Media::getTimezoneOffsetMin().
 */
final readonly class SolarEnricher implements SingleMetadataExtractorInterface
{
    private SolarEventCache $cache;

    public function __construct(
        private CaptureTimeResolver $captureTimeResolver,
        private int $goldenMinutes = 60,
        ?SolarEventCache $cache = null,
    ) {
        $this->cache = $cache ?? new SolarEventCache();
    }

    public function supports(string $filepath, Media $media): bool
    {
        return ($media->getTakenAt() instanceof DateTimeImmutable
                || $media->getCapturedLocal() instanceof DateTimeImmutable)
            && $media->getGpsLat() !== null
            && $media->getGpsLon() !== null;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $local = $this->captureTimeResolver->resolve($media);
        if (!$local instanceof DateTimeImmutable) {
            return $media;
        }

        $lat = $media->getGpsLat();
        $lon = $media->getGpsLon();
        assert($lat !== null && $lon !== null);

        $cacheKey = $this->buildCacheKey($local, $lat, $lon);
        $sunEvents = $this->cache->get($cacheKey);
        if (!$sunEvents instanceof SolarEventResult) {
            $sunEvents = $this->sunTimesUtc($local, $lat, $lon);
            $this->cache->set($cacheKey, $sunEvents);
        }

        if (!$sunEvents->isRegularDay()) {
            $features                  = $media->getFeatures() ?? [];
            $features['isGoldenHour']  = false;
            $features['isPolarDay']    = $sunEvents->isPolarDay;
            $features['isPolarNight']  = $sunEvents->isPolarNight;
            $media->setFeatures($features);

            return $media;
        }

        $offsetSec = $local->getOffset();
        if ($offsetSec === 0 && $media->getTimezoneOffsetMin() !== null) {
            $offsetSec = $media->getTimezoneOffsetMin() * 60;
        }

        $sunriseLocal = $sunEvents->sunriseUtc + $offsetSec;
        $sunsetLocal  = $sunEvents->sunsetUtc + $offsetSec;
        $photoLocal   = $local->getTimestamp();

        $delta    = $this->goldenMinutes * 60;
        $isGolden = ($photoLocal >= $sunriseLocal && $photoLocal <= ($sunriseLocal + $delta))
            || ($photoLocal >= ($sunsetLocal - $delta) && $photoLocal <= $sunsetLocal);

        $features                  = $media->getFeatures() ?? [];
        $features['isGoldenHour']  = $isGolden;
        $features['isPolarDay']    = false;
        $features['isPolarNight']  = false;
        $media->setFeatures($features);

        return $media;
    }

    /**
     * Compute sunrise/sunset for the given date (UTC seconds),
     * using a calendar-free Julian day implementation.
     *
     * @return SolarEventResult
     */
    private function sunTimesUtc(DateTimeImmutable $day, float $lat, float $lon): SolarEventResult
    {
        // Date parts in UTC (we treat $day as nominal local date; that's fine for heuristics).
        $y = (int) $day->format('Y');
        $m = (int) $day->format('n');
        $d = (int) $day->format('j');

        // Julian Day Number for 00:00 UTC (Fliegel–Van Flandern)
        $Jday = $this->jdn($y, $m, $d);

        // Meeus-like approximation (without need of "gregoriantojd" method)
        $n       = $Jday - 2451545.0 + 0.0008;
        $Japprox = $n - ($lon / 360.0);

        $M      = fmod(357.5291 + 0.98560028 * $Japprox, 360.0);
        $C      = 1.9148 * sin(deg2rad($M)) + 0.0200 * sin(2.0 * deg2rad($M)) + 0.0003 * sin(3.0 * deg2rad($M));
        $lambda = fmod($M + 102.9372 + $C + 180.0, 360.0);

        $Jtransit = 2451545.0 + $Japprox + 0.0053 * sin(deg2rad($M)) - 0.0069 * sin(2.0 * deg2rad($lambda));
        $delta    = asin(sin(deg2rad($lambda)) * sin(deg2rad(23.44)));

        $latR = deg2rad($lat);
        $cosH = (sin(deg2rad(-0.83)) - sin($latR) * sin($delta)) / (cos($latR) * cos($delta));
        if ($cosH < -1.0) {
            return new SolarEventResult(null, null, true, false);
        }

        if ($cosH > 1.0) {
            return new SolarEventResult(null, null, false, true);
        }

        $H     = acos($cosH);
        $Jset  = $Jtransit + rad2deg($H) / 360.0;
        $Jrise = $Jtransit - rad2deg($H) / 360.0;

        // Convert Julian Day to Unix time (UTC): JD(Unix epoch) = 2440587.5
        $sunriseUtc = $this->julianDayToUnix($Jrise);
        $sunsetUtc  = $this->julianDayToUnix($Jset);

        return new SolarEventResult($sunriseUtc, $sunsetUtc, false, false);
    }

    /**
     * Julian Day Number for Gregorian date (UTC midnight), calendar-free.
     * Fliegel & Van Flandern algorithm.
     */
    private function jdn(int $y, int $m, int $d): int
    {
        $a  = intdiv(14 - $m, 12);
        $yy = $y + 4800 - $a;
        $mm = $m + 12 * $a - 3;

        return $d
            + intdiv(153 * $mm + 2, 5)
            + 365 * $yy
            + intdiv($yy, 4)
            - intdiv($yy, 100)
            + intdiv($yy, 400)
            - 32045;
    }

    /**
     * Convert Julian Day (days since noon UTC) to Unix timestamp (seconds UTC).
     * JD(epoch) = 2440587.5  → unix = (JD - 2440587.5) * 86400.
     */
    private function julianDayToUnix(float $jd): int
    {
        return (int) floor(($jd - 2440587.5) * 86400.0);
    }

    private function buildCacheKey(DateTimeImmutable $day, float $lat, float $lon): string
    {
        return sprintf('%s#%.4f#%.4f', $day->format('Y-m-d'), round($lat, 4), round($lon, 4));
    }
}
