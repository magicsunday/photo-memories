<?php 

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\HomeLocatorInterface;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function assert;
use function count;
use function intdiv;
use function is_array;
use function is_string;
use function max;
use function round;
use function strtolower;

/**
 * Default implementation that derives the home location from timestamped media.
 */
final class DefaultHomeLocator implements HomeLocatorInterface
{
    private const int NIGHT_START_HOUR = 22;

    private const int NIGHT_END_HOUR = 6;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly float $defaultHomeRadiusKm = 15.0,
        private readonly ?float $homeLat = null,
        private readonly ?float $homeLon = null,
        private readonly ?float $homeRadiusKm = null,
    ) {
        if ($this->timezone === '') {
            throw new InvalidArgumentException('timezone must not be empty.');
        }

        if ($this->defaultHomeRadiusKm <= 0.0) {
            throw new InvalidArgumentException('defaultHomeRadiusKm must be > 0.');
        }

        if ($this->homeLat !== null && ($this->homeLat < -90.0 || $this->homeLat > 90.0)) {
            throw new InvalidArgumentException('homeLat must be within -90 and 90 degrees.');
        }

        if ($this->homeLon !== null && ($this->homeLon < -180.0 || $this->homeLon > 180.0)) {
            throw new InvalidArgumentException('homeLon must be within -180 and 180 degrees.');
        }

        if ($this->homeRadiusKm !== null && $this->homeRadiusKm <= 0.0) {
            throw new InvalidArgumentException('homeRadiusKm must be > 0 when provided.');
        }
    }

    /**
     * @param list<Media> $items
     *
     * @return null|array
     * @throws \DateInvalidTimeZoneException
     * @throws \DateMalformedStringException
     */
    public function determineHome(array $items): ?array
    {
        $tz = new DateTimeZone($this->timezone);

        if ($this->homeLat !== null && $this->homeLon !== null) {
            $radius = $this->homeRadiusKm ?? $this->defaultHomeRadiusKm;

            $country = null;
            $locationInfo = $tz->getLocation();
            if (is_array($locationInfo)) {
                $countryCode = $locationInfo['country_code'] ?? null;
                if (is_string($countryCode) && $countryCode !== '') {
                    $country = strtolower($countryCode);
                }
            }

            $offsetSeconds = (new DateTimeImmutable('now', $tz))->getOffset();
            $timezoneOffset = intdiv($offsetSeconds, 60);

            return [
                'lat'             => $this->homeLat,
                'lon'             => $this->homeLon,
                'radius_km'       => $radius,
                'country'         => $country,
                'timezone_offset' => $timezoneOffset,
            ];
        }

        /**
         * @var array<string, array{members:list<Media>, countryCounts:array<string,int>, offsets:array<int,int>}> $clusters
         */
        $clusters = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $local = $takenAt->setTimezone($tz);
            $hour  = (int) $local->format('G');

            if ($hour >= self::NIGHT_START_HOUR || $hour < self::NIGHT_END_HOUR) {
                continue;
            }

            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();
            if ($lat === null || $lon === null) {
                continue;
            }

            $key = $this->homeClusterKey($media, $lat, $lon);
            if (!isset($clusters[$key])) {
                $clusters[$key] = [
                    'members'       => [],
                    'countryCounts' => [],
                    'offsets'       => [],
                ];
            }

            $clusters[$key]['members'][] = $media;

            $location = $media->getLocation();
            if ($location instanceof Location) {
                $countryCode = $location->getCountryCode();
                $country     = $countryCode ?? $location->getCountry();
                if ($country !== null) {
                    $countryKey = strtolower($country);
                    if (!isset($clusters[$key]['countryCounts'][$countryKey])) {
                        $clusters[$key]['countryCounts'][$countryKey] = 0;
                    }

                    ++$clusters[$key]['countryCounts'][$countryKey];
                }
            }

            $offset = $media->getTimezoneOffsetMin();
            if ($offset !== null) {
                if (!isset($clusters[$key]['offsets'][$offset])) {
                    $clusters[$key]['offsets'][$offset] = 0;
                }

                ++$clusters[$key]['offsets'][$offset];
            }
        }

        if ($clusters === []) {
            return null;
        }

        $bestKey   = null;
        $bestCount = 0;
        foreach ($clusters as $key => $data) {
            $count = count($data['members']);
            if ($count > $bestCount) {
                $bestKey   = $key;
                $bestCount = $count;
            }
        }

        if ($bestKey === null) {
            return null;
        }

        $members  = $clusters[$bestKey]['members'];
        $centroid = MediaMath::centroid($members);

        $maxDistance = 0.0;
        foreach ($members as $media) {
            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();
            assert($lat !== null && $lon !== null);

            $distance = MediaMath::haversineDistanceInMeters(
                $lat,
                $lon,
                $centroid['lat'],
                $centroid['lon'],
            ) / 1000.0;

            if ($distance > $maxDistance) {
                $maxDistance = $distance;
            }
        }

        $country   = null;
        $countries = $clusters[$bestKey]['countryCounts'];
        if ($countries !== []) {
            $maxCountryCount = 0;
            foreach ($countries as $countryKey => $count) {
                if ($count > $maxCountryCount) {
                    $maxCountryCount = $count;
                    $country         = $countryKey;
                }
            }
        }

        $offsets = $clusters[$bestKey]['offsets'];
        $timezoneOffset = null;
        if ($offsets !== []) {
            $maxOffsetCount = 0;
            foreach ($offsets as $offsetValue => $count) {
                if ($count > $maxOffsetCount) {
                    $maxOffsetCount = $count;
                    $timezoneOffset = $offsetValue;
                }
            }
        }

        return [
            'lat'             => $centroid['lat'],
            'lon'             => $centroid['lon'],
            'radius_km'       => max($maxDistance, $this->defaultHomeRadiusKm),
            'country'         => $country,
            'timezone_offset' => $timezoneOffset,
        ];
    }

    private function homeClusterKey(Media $media, float $lat, float $lon): string
    {
        $location = $media->getLocation();
        if ($location instanceof Location) {
            $cell = $location->getCell();
            if ($cell !== '') {
                return 'cell:' . $cell;
            }

            $countryCode = $location->getCountryCode();
            if ($countryCode !== null) {
                return 'country:' . strtolower($countryCode);
            }

            $country = $location->getCountry();
            if ($country !== null && $country !== '') {
                return 'country:' . strtolower($country);
            }
        }

        $latKey = (string) round($lat, 3);
        $lonKey = (string) round($lon, 3);

        return 'coord:' . $latKey . ':' . $lonKey;
    }
}
