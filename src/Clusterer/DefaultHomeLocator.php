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
use function ceil;
use function intdiv;
use function is_array;
use function is_string;
use function max;
use function min;
use function round;
use function strtolower;
use function sort;
use function usort;

use const SORT_NUMERIC;

/**
 * Default implementation that derives the home location from timestamped media.
 */
final readonly class DefaultHomeLocator implements HomeLocatorInterface
{
    private const int NIGHT_START_HOUR = 22;

    private const int NIGHT_END_HOUR = 6;

    private const float NIGHT_RADIUS_PERCENTILE = 0.95;

    private const float MIN_NIGHT_RADIUS_KM = 10.0;

    private const float MAX_NIGHT_RADIUS_KM = 25.0;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly float $defaultHomeRadiusKm = 15.0,
        private readonly ?float $homeLat = null,
        private readonly ?float $homeLon = null,
        private readonly ?float $homeRadiusKm = null,
        private readonly int $maxHomeCenters = 3,
        private readonly float $fallbackRadiusScale = 1.5,
    ) {
        if ($this->timezone === '') {
            throw new InvalidArgumentException('timezone must not be empty.');
        }

        if ($this->defaultHomeRadiusKm <= 0.0) {
            throw new InvalidArgumentException('defaultHomeRadiusKm must be > 0.');
        }

        if ($this->maxHomeCenters < 1) {
            throw new InvalidArgumentException('maxHomeCenters must be >= 1.');
        }

        if ($this->fallbackRadiusScale < 1.0) {
            throw new InvalidArgumentException('fallbackRadiusScale must be >= 1.');
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
     * @return array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int,centers:list<array{lat:float,lon:float,radius_km:float,member_count:int,dwell_seconds:int,country:?string,timezone_offset:?int,valid_from:?int,valid_until:?int}>}|null
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
                'centers'         => [[
                    'lat'             => $this->homeLat,
                    'lon'             => $this->homeLon,
                    'radius_km'       => $radius,
                    'member_count'    => 0,
                    'dwell_seconds'   => 0,
                    'country'         => $country,
                    'timezone_offset' => $timezoneOffset,
                    'valid_from'      => null,
                    'valid_until'     => null,
                ]],
            ];
        }

        /**
         * @var array<string, array{
         *     members:list<Media>,
         *     nightSamples:list<array{lat:float,lon:float}>,
         *     countryCounts:array<string,int>,
         *     offsets:array<int,int>,
         *     firstTimestamp:int|null,
         *     lastTimestamp:int|null,
         * }> $clusters
         */
        $clusters = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $local = $takenAt->setTimezone($tz);
            $hour  = (int) $local->format('G');

            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();
            if ($lat === null || $lon === null) {
                continue;
            }

            $key = $this->homeClusterKey($media, $lat, $lon);
            if (!isset($clusters[$key])) {
                $clusters[$key] = [
                    'members'       => [],
                    'nightSamples'  => [],
                    'countryCounts' => [],
                    'offsets'       => [],
                    'firstTimestamp' => null,
                    'lastTimestamp'  => null,
                ];
            }

            $timestamp = $local->getTimestamp();
            $first     = $clusters[$key]['firstTimestamp'];
            $last      = $clusters[$key]['lastTimestamp'];

            if ($first === null || $timestamp < $first) {
                $clusters[$key]['firstTimestamp'] = $timestamp;
            }

            if ($last === null || $timestamp > $last) {
                $clusters[$key]['lastTimestamp'] = $timestamp;
            }

            $isNight = $hour >= self::NIGHT_START_HOUR || $hour < self::NIGHT_END_HOUR;

            if ($isNight) {
                $clusters[$key]['nightSamples'][] = [
                    'lat' => $lat,
                    'lon' => $lon,
                ];
            } else {
                $clusters[$key]['members'][] = $media;
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

        $summaries = $this->summariseClusters($clusters);
        if ($summaries === []) {
            return null;
        }

        usort($summaries, static function (array $a, array $b): int {
            if ($a['dwell_seconds'] === $b['dwell_seconds']) {
                if ($a['member_count'] === $b['member_count']) {
                    return $b['radius_km'] <=> $a['radius_km'];
                }

                return $b['member_count'] <=> $a['member_count'];
            }

            return $b['dwell_seconds'] <=> $a['dwell_seconds'];
        });

        $centers = array_slice($summaries, 0, $this->maxHomeCenters);
        $primary = $centers[0];

        return [
            'lat'             => $primary['lat'],
            'lon'             => $primary['lon'],
            'radius_km'       => $primary['radius_km'],
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

    /**
     * @param array<string, array{members:list<Media>,nightSamples:list<array{lat:float,lon:float}>,countryCounts:array<string,int>,offsets:array<int,int>,firstTimestamp:int|null,lastTimestamp:int|null}> $clusters
     *
     * @return list<array{
     *     lat:float,
     *     lon:float,
     *     radius_km:float,
     *     member_count:int,
     *     dwell_seconds:int,
     *     country:?string,
     *     timezone_offset:?int,
     *     valid_from:?int,
     *     valid_until:?int,
     * }>
     */
    private function summariseClusters(array $clusters): array
    {
        $summaries = [];

        foreach ($clusters as $data) {
            $members = $data['members'];
            $nightSamples = $data['nightSamples'];

            if ($members === [] && $nightSamples === []) {
                continue;
            }

            if ($members !== []) {
                $centroid = MediaMath::centroid($members);
            } else {
                $centroid = $this->centroidFromNightSamples($nightSamples);
            }

            $maxDistanceKm = 0.0;
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

                if ($distance > $maxDistanceKm) {
                    $maxDistanceKm = $distance;
                }
            }

            $country         = $this->majorityCountry($data['countryCounts']);
            $timezoneOffset  = $this->majorityOffset($data['offsets']);
            $memberCount     = count($members);
            $dwellSeconds    = $this->dwellSeconds($data['firstTimestamp'], $data['lastTimestamp']);
            $radius          = $this->radiusForCluster(
                $centroid,
                $nightSamples,
                $memberCount,
                $maxDistanceKm,
                $dwellSeconds,
            );

            $summaries[] = [
                'lat'             => $centroid['lat'],
                'lon'             => $centroid['lon'],
                'radius_km'       => $radius,
                'member_count'    => $memberCount,
                'dwell_seconds'   => $dwellSeconds,
                'country'         => $country,
                'timezone_offset' => $timezoneOffset,
                'valid_from'      => $data['firstTimestamp'],
                'valid_until'     => $data['lastTimestamp'],
            ];
        }

        return $summaries;
    }

    private function majorityCountry(array $countries): ?string
    {
        $bestCountry = null;
        $bestCount   = 0;

        foreach ($countries as $country => $count) {
            if ($count > $bestCount) {
                $bestCount   = $count;
                $bestCountry = $country;
            }
        }

        return $bestCountry;
    }

    private function majorityOffset(array $offsets): ?int
    {
        $bestOffset = null;
        $bestCount  = 0;

        foreach ($offsets as $offset => $count) {
            if ($count > $bestCount) {
                $bestCount  = $count;
                $bestOffset = $offset;
            }
        }

        return $bestOffset;
    }

    private function dwellSeconds(?int $firstTimestamp, ?int $lastTimestamp): int
    {
        if ($firstTimestamp === null || $lastTimestamp === null) {
            return 0;
        }

        if ($lastTimestamp < $firstTimestamp) {
            return 0;
        }

        return $lastTimestamp - $firstTimestamp;
    }

    private function adaptiveRadius(int $memberCount, float $maxDistanceKm, int $dwellSeconds): float
    {
        $radius = max($maxDistanceKm, $this->defaultHomeRadiusKm);

        if ($maxDistanceKm >= $this->defaultHomeRadiusKm) {
            return $radius;
        }

        $dwellHours       = $dwellSeconds > 0 ? $dwellSeconds / 3600.0 : 0.0;
        $densityPerHour   = $dwellHours > 0.0 ? $memberCount / $dwellHours : (float) $memberCount;
        $hasExtendedDwell = $dwellHours >= 8.0;
        $hasHighDensity   = $densityPerHour >= 2.0;

        if ($hasExtendedDwell || $hasHighDensity) {
            $radius = max($radius, $this->defaultHomeRadiusKm * $this->fallbackRadiusScale);
        }

        return $radius;
    }

    /**
     * @param list<array{lat:float,lon:float}> $nightSamples
     *
     * @return array{lat:float,lon:float}
     */
    private function centroidFromNightSamples(array $nightSamples): array
    {
        $sumLat = 0.0;
        $sumLon = 0.0;
        $count  = count($nightSamples);

        foreach ($nightSamples as $sample) {
            $sumLat += $sample['lat'];
            $sumLon += $sample['lon'];
        }

        if ($count === 0) {
            return ['lat' => 0.0, 'lon' => 0.0];
        }

        return [
            'lat' => $sumLat / $count,
            'lon' => $sumLon / $count,
        ];
    }

    /**
     * @param list<array{lat:float,lon:float}> $nightSamples
     */
    private function radiusForCluster(
        array $centroid,
        array $nightSamples,
        int $memberCount,
        float $maxDistanceKm,
        int $dwellSeconds,
    ): float {
        if ($nightSamples === []) {
            return $this->adaptiveRadius($memberCount, $maxDistanceKm, $dwellSeconds);
        }

        $distances = [];
        foreach ($nightSamples as $sample) {
            $distances[] = MediaMath::haversineDistanceInMeters(
                $sample['lat'],
                $sample['lon'],
                $centroid['lat'],
                $centroid['lon'],
            ) / 1000.0;
        }

        if ($distances === []) {
            return $this->adaptiveRadius($memberCount, $maxDistanceKm, $dwellSeconds);
        }

        sort($distances, SORT_NUMERIC);

        $count = count($distances);
        $index = (int) ceil($count * self::NIGHT_RADIUS_PERCENTILE) - 1;
        if ($index < 0) {
            $index = 0;
        } elseif ($index >= $count) {
            $index = $count - 1;
        }

        $radius = $distances[$index];

        return max(self::MIN_NIGHT_RADIUS_KM, min(self::MAX_NIGHT_RADIUS_KM, $radius));
    }
}
