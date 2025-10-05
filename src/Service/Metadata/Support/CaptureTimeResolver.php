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
            $confidence = $media->getTzConfidence();
            if ($confidence !== null && $confidence >= 0.8) {
                return $capturedLocal;
            }
        }

        $takenAt = $media->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        $determined = $this->determineTimezone($media, $takenAt);
        if ($determined !== null) {
            $timezone = $determined['timezone'];
            $source   = $determined['source'];

            $local = $takenAt->setTimezone($timezone);
            $media->setCapturedLocal($local);
            if ($media->getTzId() === null) {
                $media->setTzId($timezone->getName());
            }

            if ($source === 'tzId') {
                if ($media->getTzConfidence() === null) {
                    $this->promoteTzConfidence($media, 0.9);
                }
            } else {
                $this->promoteTzConfidence($media, 0.8);
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

            $this->promoteTzConfidence($media, 1.0);

            return $local;
        }

        $media->setCapturedLocal($takenAt);
        $this->promoteTzConfidence($media, 0.2);

        return $takenAt;
    }

    /**
     * @return array{timezone: DateTimeZone, source: 'tzId'|'resolver'}|null
     */
    private function determineTimezone(Media $media, DateTimeImmutable $takenAt): ?array
    {
        $tzId = $media->getTzId();
        if (is_string($tzId) && $tzId !== '') {
            try {
                return ['timezone' => new DateTimeZone($tzId), 'source' => 'tzId'];
            } catch (Exception) {
                $media->setTzId(null);
            }
        }

        $location = $media->getLocation();
        if (!$location instanceof Location) {
            return null;
        }

        try {
            $timezone = $this->timezoneResolver->resolveMediaTimezone($media, $takenAt, $this->home);

            return ['timezone' => $timezone, 'source' => 'resolver'];
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

    private function promoteTzConfidence(Media $media, float $confidence): void
    {
        $current = $media->getTzConfidence();

        if ($current === null || $confidence > $current) {
            $media->setTzConfidence($confidence);
        }
    }
}
