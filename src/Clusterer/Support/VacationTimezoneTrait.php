<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Shared helpers for resolving media timezones within vacation clustering.
 */
trait VacationTimezoneTrait
{
    /**
     * @param array{localTimezoneIdentifier:string,localTimezoneOffset:int|null,timezoneOffsets:array<int,int>} $summary
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null,centers:list<array{lat:float,lon:float,radius_km:float,member_count:int,dwell_seconds:int}>}           $home
     */
    private function resolveSummaryTimezone(array $summary, array $home): DateTimeZone
    {
        $identifier = $summary['localTimezoneIdentifier'] ?? null;
        if (is_string($identifier) && $identifier !== '') {
            try {
                return new DateTimeZone($identifier);
            } catch (Exception) {
                // ignore invalid identifier and fall back to offsets
            }
        }

        $offset = $summary['localTimezoneOffset'] ?? null;

        return $this->createTimezoneFromOffset($offset ?? $home['timezone_offset']);
    }

    private function createTimezoneFromOffset(?int $offsetMinutes): DateTimeZone
    {
        if ($offsetMinutes === null) {
            return new DateTimeZone($this->timezone);
        }

        $sign       = $offsetMinutes >= 0 ? '+' : '-';
        $absMinutes = abs($offsetMinutes);
        $hours      = intdiv($absMinutes, 60);
        $minutes    = $absMinutes % 60;

        return new DateTimeZone(
            sprintf('%s%02d:%02d', $sign, $hours, $minutes));
    }

    /**
     * @param Media                                                                                   $media
     * @param DateTimeImmutable                                                                       $takenAt
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null,centers:list<array{lat:float,lon:float,radius_km:float,member_count:int,dwell_seconds:int}>} $home
     *
     * @return DateTimeZone
     *
     * @throws DateInvalidTimeZoneException
     */
    private function resolveMediaTimezone(Media $media, DateTimeImmutable $takenAt, array $home): DateTimeZone
    {
        $offset = $media->getTimezoneOffsetMin();
        if ($offset !== null) {
            return $this->createTimezoneFromOffset($offset);
        }

        $location = $media->getLocation();
        if ($location instanceof Location) {
            $identifier = $this->extractTimezoneIdentifierFromLocation($location);
            if ($identifier !== null) {
                try {
                    return new DateTimeZone($identifier);
                } catch (Exception) {
                    // ignore invalid identifier and fall back to other heuristics
                }
            }
        }

        $timezone = $takenAt->getTimezone();
        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }

        $homeOffset = $home['timezone_offset'] ?? null;
        if ($homeOffset !== null) {
            return $this->createTimezoneFromOffset($homeOffset);
        }

        return new DateTimeZone($this->timezone);
    }

    private function extractTimezoneIdentifierFromLocation(Location $location): ?string
    {
        $pois = $location->getPois();
        if (!is_array($pois)) {
            return null;
        }

        foreach ($pois as $poi) {
            if (!is_array($poi)) {
                continue;
            }

            $direct = $poi['timezone'] ?? null;
            if (is_string($direct) && $direct !== '') {
                return $direct;
            }

            $tags = $poi['tags'] ?? null;
            if (!is_array($tags)) {
                continue;
            }

            foreach (['timezone', 'opening_hours:timezone', 'tz'] as $key) {
                $value = $tags[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
