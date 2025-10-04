<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MagicSunday\Memories\Clusterer\Contract\TimezoneResolverInterface;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;

use function abs;
use function intdiv;
use function is_string;
use function sprintf;

/**
 * Resolves the best available local capture timestamp for media items.
 */
final class CaptureTimeResolver
{
    /**
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     */
    public function __construct(
        private readonly TimezoneResolverInterface $timezoneResolver,
        private array $home = [
            'lat' => 0.0,
            'lon' => 0.0,
            'radius_km' => 0.0,
            'country' => null,
            'timezone_offset' => null,
        ],
    ) {
    }

    public function resolve(Media $media): ?DateTimeImmutable
    {
        $capturedLocal = $media->getCapturedLocal();
        if ($capturedLocal instanceof DateTimeImmutable) {
            return $capturedLocal;
        }

        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        $timezone = $this->determineTimezone($media, $takenAt);
        if ($timezone instanceof DateTimeZone) {
            $local = $takenAt->setTimezone($timezone);
            $media->setCapturedLocal($local);
            if ($media->getTzId() === null) {
                $media->setTzId($timezone->getName());
            }

            return $local;
        }

        $offset = $media->getTimezoneOffsetMin();
        if ($offset !== null) {
            $local = $this->applyOffset($takenAt, $offset);
            $media->setCapturedLocal($local);
            if ($media->getTzId() === null) {
                $media->setTzId($local->getTimezone()->getName());
            }

            return $local;
        }

        $media->setCapturedLocal($takenAt);

        return $takenAt;
    }

    private function determineTimezone(Media $media, DateTimeImmutable $takenAt): ?DateTimeZone
    {
        $tzId = $media->getTzId();
        if (is_string($tzId) && $tzId !== '') {
            try {
                return new DateTimeZone($tzId);
            } catch (Exception) {
                $media->setTzId(null);
            }
        }

        $location = $media->getLocation();
        if (!$location instanceof Location) {
            return null;
        }

        try {
            return $this->timezoneResolver->resolveMediaTimezone($media, $takenAt, $this->home);
        } catch (Exception) {
            return null;
        }
    }

    private function applyOffset(DateTimeImmutable $instant, int $offsetMinutes): DateTimeImmutable
    {
        $sign = $offsetMinutes >= 0 ? '+' : '-';
        $abs  = abs($offsetMinutes);
        $hours = intdiv($abs, 60);
        $minutes = $abs % 60;
        $spec = sprintf('%s%02d:%02d', $sign, $hours, $minutes);

        try {
            $timezone = new DateTimeZone($spec);
        } catch (Exception) {
            return $instant;
        }

        return $instant->setTimezone($timezone);
    }
}
