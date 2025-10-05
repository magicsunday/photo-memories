<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\HomeLocatorInterface;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_slice;
use function assert;
use function count;
use function intdiv;
use function is_array;
use function is_string;
use function max;
use function min;
use function round;
use function strtolower;
use function usort;

/**
 * Default implementation that derives the home location from timestamped media.
 */
final readonly class DefaultHomeLocator implements HomeLocatorInterface
{
    private const int NIGHT_START_HOUR = 22;

    private const int NIGHT_END_HOUR = 6;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly float $defaultHomeRadiusKm = 15.0,
        private readonly ?float $homeLat = null,
        private readonly ?float $homeLon = null,
        private readonly ?float $homeRadiusKm = null,
        private readonly int $maxCenters = 1,
        private readonly float $fallbackRadiusScale = 1.5,
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

        if ($this->maxCenters < 1) {
            throw new InvalidArgumentException('maxCenters must be >= 1.');
        }

        if ($this->fallbackRadiusScale < 1.0) {
            throw new InvalidArgumentException('fallbackRadiusScale must be >= 1.');
        }
    }

    /**
     * @param list<Media> $items
     *
     * @return array|null
     *
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     */
    public function determineHome(array $items): ?array
    {
        $tz = new DateTimeZone($this->timezone);

        if ($this->homeLat !== null && $this->homeLon !== null) {
            $radius = $this->homeRadiusKm ?? $this->defaultHomeRadiusKm;

            $country      = null;
            $locationInfo = $tz->getLocation();
            if (is_array($locationInfo)) {
                $countryCode = $locationInfo['country_code'] ?? null;
                if (is_string($countryCode) && $countryCode !== '') {
                    $country = strtolower($countryCode);
                }
            }

            $offsetSeconds  = (new DateTimeImmutable('now', $tz))->getOffset();
            $timezoneOffset = intdiv($offsetSeconds, 60);

            return [
                'lat'             => $this->homeLat,
                'lon'             => $this->homeLon,
                'radius_km'       => $radius,
                'country'         => $country,
                'timezone_offset' => $timezoneOffset,
                'centers'         => [
                    [
                        'lat'         => $this->homeLat,
                        'lon'         => $this->homeLon,
                        'radius_km'   => $radius,
                        'member_count' => 0,
                        'dwell_seconds' => 0,
                    ],
                ],
            ];
        }

        /**
         * @var array<string, array{members:list<Media>, countryCounts:array<string,int>, offsets:array<int,int>, firstTimestamp:int, lastTimestamp:int}> $clusters
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
            $timestamp = $takenAt->getTimestamp();

            if (!isset($clusters[$key])) {
                $clusters[$key] = [
                    'members'        => [],
                    'countryCounts'  => [],
                    'offsets'        => [],
                    'firstTimestamp' => $timestamp,
                    'lastTimestamp'  => $timestamp,
                ];
            }

            $clusters[$key]['members'][] = $media;
            if ($timestamp < $clusters[$key]['firstTimestamp']) {
                $clusters[$key]['firstTimestamp'] = $timestamp;
            }

            if ($timestamp > $clusters[$key]['lastTimestamp']) {
                $clusters[$key]['lastTimestamp'] = $timestamp;
            }

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

        /**
         * @var list<array{
         *     score: float,
         *     center: array{lat:float,lon:float,radius_km:float,member_count:int,dwell_seconds:int},
         *     country: string|null,
         *     timezone_offset: int|null
         * }> $ranked
         */
        $ranked = [];

        foreach ($clusters as $data) {
            $members = $data['members'];
            if ($members === []) {
                continue;
            }

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

            $dwellSeconds = $data['lastTimestamp'] - $data['firstTimestamp'];
            if ($dwellSeconds < 0) {
                $dwellSeconds = 0;
            }

            $memberCount = count($members);

            $radius = $this->computeAdaptiveRadius(
                $maxDistance,
                $memberCount,
                $dwellSeconds,
            );

            $country   = null;
            $countries = $data['countryCounts'];
            if ($countries !== []) {
                $maxCountryCount = 0;
                foreach ($countries as $countryKey => $count) {
                    if ($count > $maxCountryCount) {
                        $maxCountryCount = $count;
                        $country         = $countryKey;
                    }
                }
            }

            $offsets        = $data['offsets'];
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

            $score = (float) $dwellSeconds + (float) $memberCount * 600.0;

            $ranked[] = [
                'score'            => $score,
                'center'           => [
                    'lat'           => $centroid['lat'],
                    'lon'           => $centroid['lon'],
                    'radius_km'     => $radius,
                    'member_count'  => $memberCount,
                    'dwell_seconds' => $dwellSeconds,
                ],
                'country'          => $country,
                'timezone_offset'  => $timezoneOffset,
            ];
        }

        if ($ranked === []) {
            return null;
        }

        usort(
            $ranked,
            static fn (array $a, array $b): int => $b['score'] <=> $a['score'],
        );

        $selected = array_slice($ranked, 0, $this->maxCenters);

        $primary = $selected[0];

        $centers = [];
        foreach ($selected as $entry) {
            $centers[] = $entry['center'];
        }

        return [
            'lat'             => $primary['center']['lat'],
            'lon'             => $primary['center']['lon'],
            'radius_km'       => $primary['center']['radius_km'],
            'country'         => $primary['country'],
            'timezone_offset' => $primary['timezone_offset'],
            'centers'         => $centers,
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

    private function computeAdaptiveRadius(float $maxDistance, int $memberCount, int $dwellSeconds): float
    {
        $radius = max($maxDistance, $this->defaultHomeRadiusKm);

        if ($maxDistance >= $this->defaultHomeRadiusKm) {
            return $radius;
        }

        $hours       = $dwellSeconds / 3600.0;
        $sampleFactor = min(1.0, (float) $memberCount / 12.0);
        $dwellFactor  = min(1.0, $hours / 12.0);
        $densityFactor = max($sampleFactor, $dwellFactor);

        $scale = 1.0 + ($this->fallbackRadiusScale - 1.0) * $densityFactor;

        return max($radius, $this->defaultHomeRadiusKm * $scale);
    }
}
