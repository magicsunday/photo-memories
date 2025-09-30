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
use MagicSunday\Memories\Clusterer\Contract\DaySummaryBuilderInterface;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\VacationTimezoneTrait;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;

use function array_keys;
use function array_slice;
use function array_sum;
use function assert;
use function count;
use function in_array;
use function intdiv;
use function is_array;
use function is_string;
use function max;
use function sqrt;
use function str_contains;
use function strtolower;
use function usort;

use const SORT_STRING;

/**
 * Default implementation that prepares per-day vacation summaries.
 */
final class DefaultDaySummaryBuilder implements DaySummaryBuilderInterface
{
    use MediaFilterTrait;
    use VacationTimezoneTrait;

    private const array TOURISM_KEYWORDS = [
        'tourism',
        'attraction',
        'beach',
        'museum',
        'national_park',
        'viewpoint',
        'hotel',
        'camp_site',
        'ski',
        'marina',
    ];

    private const array TRANSPORT_KEYWORDS = [
        'airport',
        'aerodrome',
        'railway_station',
        'train_station',
        'bus_station',
    ];

    private const float MIN_STD_EPSILON = 1.0e-6;

    public function __construct(
        private GeoDbscanHelper $dbscanHelper,
        private string $timezone = 'Europe/Berlin',
        private float $gpsOutlierRadiusKm = 1.0,
        private int $gpsOutlierMinSamples = 3,
        private int $minItemsPerDay = 3,
    ) {
        if ($this->timezone === '') {
            throw new InvalidArgumentException('timezone must not be empty.');
        }

        if ($this->gpsOutlierRadiusKm <= 0.0) {
            throw new InvalidArgumentException('gpsOutlierRadiusKm must be > 0.');
        }

        if ($this->gpsOutlierMinSamples < 2) {
            throw new InvalidArgumentException('gpsOutlierMinSamples must be >= 2.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }
    }

    /**
     * @param list<Media>                                                   $items
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     */
    public function buildDaySummaries(array $items, array $home): array
    {
        /** @var array<string, array{date:string,members:list<Media>,gpsMembers:list<Media>,maxDistanceKm:float,distanceSum:float,distanceCount:int,avgDistanceKm:float,travelKm:float,countryCodes:array<string,true>,timezoneOffsets:array<int,int>,localTimezoneIdentifier:string,localTimezoneOffset:int|null,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,isAwayCandidate:bool,sufficientSamples:bool,spotClusters:list<list<Media>>,spotNoise:list<Media>,spotCount:int,spotNoiseSamples:int,spotDwellSeconds:int,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,baseLocation:array{lat:float,lon:float,distance_km:float,source:string}|null,baseAway:bool,awayByDistance:bool,firstGpsMedia:Media|null,lastGpsMedia:Media|null,timezoneIdentifierVotes:array<string,int>,isSynthetic:bool}> $days */
        $days = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            assert($takenAt instanceof DateTimeImmutable);

            $mediaTimezone = $this->resolveMediaTimezone($media, $takenAt, $home);
            $local         = $takenAt->setTimezone($mediaTimezone);
            $date          = $local->format('Y-m-d');
            $offsetMinutes = intdiv($local->getOffset(), 60);
            $timezoneName  = $mediaTimezone->getName();

            if (!isset($days[$date])) {
                $days[$date] = [
                    'date'              => $date,
                    'members'           => [],
                    'gpsMembers'        => [],
                    'maxDistanceKm'     => 0.0,
                    'distanceSum'       => 0.0,
                    'distanceCount'     => 0,
                    'avgDistanceKm'     => 0.0,
                    'travelKm'          => 0.0,
                    'countryCodes'      => [],
                    'timezoneOffsets'   => [],
                    'localTimezoneIdentifier' => $timezoneName,
                    'localTimezoneOffset' => $offsetMinutes,
                    'tourismHits'       => 0,
                    'poiSamples'        => 0,
                    'tourismRatio'      => 0.0,
                    'hasAirportPoi'     => false,
                    'weekday'           => (int) $local->format('N'),
                    'photoCount'        => 0,
                    'densityZ'          => 0.0,
                    'isAwayCandidate'   => false,
                    'sufficientSamples' => false,
                    'spotClusters'      => [],
                    'spotNoise'         => [],
                    'spotCount'         => 0,
                    'spotNoiseSamples'  => 0,
                    'spotDwellSeconds'  => 0,
                    'staypoints'        => [],
                    'baseLocation'      => null,
                    'baseAway'          => false,
                    'awayByDistance'    => false,
                    'firstGpsMedia'     => null,
                    'lastGpsMedia'      => null,
                    'timezoneIdentifierVotes' => [],
                    'isSynthetic'       => false,
                ];
            }

            $summary = &$days[$date];
            $summary['members'][] = $media;
            ++$summary['photoCount'];

            $lat = $media->getGpsLat();
            $lon = $media->getGpsLon();
            if ($lat !== null && $lon !== null) {
                $summary['gpsMembers'][] = $media;
            }

            if (!isset($summary['timezoneOffsets'][$offsetMinutes])) {
                $summary['timezoneOffsets'][$offsetMinutes] = 0;
            }

            ++$summary['timezoneOffsets'][$offsetMinutes];

            if (!isset($summary['timezoneIdentifierVotes'][$timezoneName])) {
                $summary['timezoneIdentifierVotes'][$timezoneName] = 0;
            }

            ++$summary['timezoneIdentifierVotes'][$timezoneName];

            $location = $media->getLocation();
            if ($location instanceof Location) {
                $countryCode = $location->getCountryCode();
                $country     = $countryCode ?? $location->getCountry();
                if ($country !== null) {
                    $summary['countryCodes'][strtolower($country)] = true;
                }

                if ($this->isPoiSample($location)) {
                    ++$summary['poiSamples'];
                }

                if ($this->isTourismPoi($location)) {
                    ++$summary['tourismHits'];
                }

                if ($this->isTransportPoi($location)) {
                    $summary['hasAirportPoi'] = true;
                }
            }

            unset($summary);
        }

        if ($days === []) {
            return [];
        }

        $days = $this->ensureContinuousDayRange($days);

        foreach ($days as &$summary) {
            $offset = $this->determineLocalTimezoneOffset($summary['timezoneOffsets'], $home);
            $summary['localTimezoneOffset'] = $offset;
            $summary['localTimezoneIdentifier'] = $this->determineLocalTimezoneIdentifier(
                $summary['timezoneIdentifierVotes'],
                $home,
                $offset,
            );

            unset($summary['timezoneIdentifierVotes']);
        }

        unset($summary);

        foreach ($days as $date => &$summary) {
            $summary['gpsMembers'] = $this->filterGpsOutliers(
                $summary['gpsMembers'],
                $this->gpsOutlierRadiusKm,
                $this->gpsOutlierMinSamples,
            );

            $summary['maxDistanceKm']   = 0.0;
            $summary['distanceSum']     = 0.0;
            $summary['distanceCount']   = 0;
            $summary['avgDistanceKm']   = 0.0;
            $summary['travelKm']        = 0.0;

            $gpsMembers = $summary['gpsMembers'];
            if ($gpsMembers !== []) {
                usort($gpsMembers, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

                $travelKm = 0.0;
                $previous = null;
                foreach ($gpsMembers as $gpsMedia) {
                    $lat = $gpsMedia->getGpsLat();
                    $lon = $gpsMedia->getGpsLon();
                    $takenAt = $gpsMedia->getTakenAt();
                    assert($lat !== null && $lon !== null && $takenAt instanceof DateTimeImmutable);

                    if ($previous instanceof Media) {
                        $travelKm += MediaMath::haversineDistanceInMeters(
                            (float) $previous->getGpsLat(),
                            (float) $previous->getGpsLon(),
                            (float) $lat,
                            (float) $lon,
                        ) / 1000.0;
                    }

                    $previous = $gpsMedia;
                }

                $summary['travelKm'] = $travelKm;

                $centroid = MediaMath::centroid($gpsMembers);
                foreach ($gpsMembers as $gpsMedia) {
                    $distance = MediaMath::haversineDistanceInMeters(
                        (float) $gpsMedia->getGpsLat(),
                        (float) $gpsMedia->getGpsLon(),
                        (float) $centroid['lat'],
                        (float) $centroid['lon'],
                    ) / 1000.0;

                    $summary['distanceSum']   += $distance;
                    ++$summary['distanceCount'];

                    if ($distance > $summary['maxDistanceKm']) {
                        $summary['maxDistanceKm'] = $distance;
                    }
                }

                if ($summary['distanceCount'] > 0) {
                    $summary['avgDistanceKm'] = $summary['distanceSum'] / $summary['distanceCount'];
                }

                $summary['firstGpsMedia'] = $gpsMembers[0];
                $summary['lastGpsMedia']  = $gpsMembers[count($gpsMembers) - 1];
                $summary['staypoints']    = $this->computeStaypoints($gpsMembers);

                $clusters = $this->dbscanHelper->clusterMedia(
                    $gpsMembers,
                    $this->gpsOutlierRadiusKm,
                    $this->gpsOutlierMinSamples,
                );

                $summary['spotClusters']     = $clusters['clusters'];
                $summary['spotNoise']        = $clusters['noise'];
                $summary['spotCount']        = count($clusters['clusters']);
                $summary['spotNoiseSamples'] = count($clusters['noise']);

                $dwellSeconds = 0;
                foreach ($summary['staypoints'] as $staypoint) {
                    $dwellSeconds += $staypoint['dwell'];
                }

                $summary['spotDwellSeconds'] = $dwellSeconds;
            }

            $summary['sufficientSamples'] = $summary['photoCount'] >= $this->minItemsPerDay;
            unset($summary);
        }

        unset($summary);

        $photoCounts = [];
        foreach ($days as $summary) {
            $photoCounts[] = $summary['photoCount'];
        }

        $stats = $this->computeMeanStd($photoCounts);
        foreach ($days as &$summary) {
            if ($stats['std'] > self::MIN_STD_EPSILON) {
                $summary['densityZ'] = ($summary['photoCount'] - $stats['mean']) / $stats['std'];
            } else {
                $summary['densityZ'] = 0.0;
            }

            unset($summary);
        }

        unset($summary);

        $keys = array_keys($days);
        foreach ($keys as $index => $key) {
            $summary = &$days[$key];
            $nextKey = $keys[$index + 1] ?? null;
            $nextSummary = $nextKey !== null ? $days[$nextKey] : null;

            $baseLocation = $this->determineBaseLocationForDay($summary, $nextSummary, $home);
            $summary['baseLocation'] = $baseLocation;

            if ($baseLocation !== null && $baseLocation['distance_km'] > $home['radius_km']) {
                $summary['baseAway'] = true;
            }

            if ($summary['avgDistanceKm'] > $home['radius_km']) {
                $summary['awayByDistance'] = true;
            }

            unset($summary);
        }

        unset($summary);

        $baseFlags     = [];
        $distanceFlags = [];
        foreach ($keys as $key) {
            $summary = $days[$key];
            $baseFlags[$key]     = $summary['baseAway'];
            $distanceFlags[$key] = $summary['awayByDistance'];
        }

        $baseFlags     = $this->applyMorphologicalClosing($baseFlags);
        $distanceFlags = $this->propagateDistanceRuns($distanceFlags);

        foreach ($keys as $key) {
            $days[$key]['baseAway'] = $baseFlags[$key] || $distanceFlags[$key];
        }

        $combinedFlags = [];
        foreach ($keys as $key) {
            $combinedFlags[$key] = $days[$key]['baseAway'];
        }

        $combinedFlags = $this->applyMorphologicalClosing($combinedFlags);
        $combinedFlags = $this->inheritSyntheticAwayFlags($combinedFlags, $keys, $days);

        foreach ($keys as $key) {
            $days[$key]['baseAway'] = $combinedFlags[$key];
        }

        return $days;
    }

    /**
     * @param array<string, array{date:string,isSynthetic:bool}> $days
     *
     * @return array<string, array{date:string,isSynthetic:bool}>
     */
    private function ensureContinuousDayRange(array $days): array
    {
        ksort($days, SORT_STRING);

        $keys = array_keys($days);
        if ($keys === []) {
            return $days;
        }

        $timezone = new DateTimeZone('UTC');

        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $keys[0], $timezone);
        $last  = DateTimeImmutable::createFromFormat('!Y-m-d', $keys[count($keys) - 1], $timezone);

        if ($first === false || $last === false) {
            return $days;
        }

        $cursor = $first;
        while ($cursor <= $last) {
            $key = $cursor->format('Y-m-d');
            if (!isset($days[$key])) {
                $days[$key] = $this->createSyntheticDaySummary($key);
            }

            $cursor = $cursor->modify('+1 day');
        }

        ksort($days, SORT_STRING);

        return $days;
    }

    /**
     * @return array{date:string,members:list<Media>,gpsMembers:list<Media>,maxDistanceKm:float,distanceSum:float,distanceCount:int,avgDistanceKm:float,travelKm:float,countryCodes:array<string,true>,timezoneOffsets:array<int,int>,localTimezoneIdentifier:string,localTimezoneOffset:int|null,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,isAwayCandidate:bool,sufficientSamples:bool,spotClusters:list<list<Media>>,spotNoise:list<Media>,spotCount:int,spotNoiseSamples:int,spotDwellSeconds:int,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,baseLocation:array{lat:float,lon:float,distance_km:float,source:string}|null,baseAway:bool,awayByDistance:bool,firstGpsMedia:Media|null,lastGpsMedia:Media|null,isSynthetic:bool,timezoneIdentifierVotes:array<string,int>}
     */
    private function createSyntheticDaySummary(string $date): array
    {
        $timezone = new DateTimeZone($this->timezone);
        $weekday  = (int) (new DateTimeImmutable($date, $timezone))->format('N');

        return [
            'date'                    => $date,
            'members'                 => [],
            'gpsMembers'              => [],
            'maxDistanceKm'           => 0.0,
            'distanceSum'             => 0.0,
            'distanceCount'           => 0,
            'avgDistanceKm'           => 0.0,
            'travelKm'                => 0.0,
            'countryCodes'            => [],
            'timezoneOffsets'         => [],
            'localTimezoneIdentifier' => $this->timezone,
            'localTimezoneOffset'     => null,
            'tourismHits'             => 0,
            'poiSamples'              => 0,
            'tourismRatio'            => 0.0,
            'hasAirportPoi'           => false,
            'weekday'                 => $weekday,
            'photoCount'              => 0,
            'densityZ'                => 0.0,
            'isAwayCandidate'         => false,
            'sufficientSamples'       => false,
            'spotClusters'            => [],
            'spotNoise'               => [],
            'spotCount'               => 0,
            'spotNoiseSamples'        => 0,
            'spotDwellSeconds'        => 0,
            'staypoints'              => [],
            'baseLocation'            => null,
            'baseAway'                => false,
            'awayByDistance'          => false,
            'firstGpsMedia'           => null,
            'lastGpsMedia'            => null,
            'isSynthetic'             => true,
            'timezoneIdentifierVotes' => [],
        ];
    }

    /**
     * @param list<Media> $gpsMembers
     *
     * @return list<array{lat:float,lon:float,start:int,end:int,dwell:int}>
     */
    private function computeStaypoints(array $gpsMembers): array
    {
        $count = count($gpsMembers);
        if ($count < 2) {
            return [];
        }

        $staypoints = [];
        $i = 0;

        while ($i < $count - 1) {
            $startMedia = $gpsMembers[$i];
            $startTime  = $startMedia->getTakenAt();
            assert($startTime instanceof DateTimeImmutable);

            $j = $i + 1;
            while ($j < $count) {
                $candidate     = $gpsMembers[$j];
                $candidateTime = $candidate->getTakenAt();
                assert($candidateTime instanceof DateTimeImmutable);

                $distanceKm = MediaMath::haversineDistanceInMeters(
                    (float) $startMedia->getGpsLat(),
                    (float) $startMedia->getGpsLon(),
                    (float) $candidate->getGpsLat(),
                    (float) $candidate->getGpsLon(),
                ) / 1000.0;

                if ($distanceKm > 0.2) {
                    break;
                }

                ++$j;
            }

            $endIndex = $j - 1;
            if ($endIndex <= $i) {
                ++$i;
                continue;
            }

            $endMedia = $gpsMembers[$endIndex];
            $endTime  = $endMedia->getTakenAt();
            assert($endTime instanceof DateTimeImmutable);

            $dwell = $endTime->getTimestamp() - $startTime->getTimestamp();
            if ($dwell >= 3600) {
                $segment  = array_slice($gpsMembers, $i, $endIndex - $i + 1);
                $centroid = MediaMath::centroid($segment);
                $staypoints[] = [
                    'lat'   => (float) $centroid['lat'],
                    'lon'   => (float) $centroid['lon'],
                    'start' => $startTime->getTimestamp(),
                    'end'   => $endTime->getTimestamp(),
                    'dwell' => $dwell,
                ];

                $i = $endIndex + 1;
            } else {
                ++$i;
            }
        }

        return $staypoints;
    }

    /**
     * @param array{date:string,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,firstGpsMedia:Media|null,lastGpsMedia:Media|null,gpsMembers:list<Media>,timezoneOffsets:array<int,int>,localTimezoneIdentifier:string,localTimezoneOffset:int|null} $summary
     * @param array{date:string,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,firstGpsMedia:Media|null}|null $nextSummary
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     *
     * @return array{lat:float,lon:float,distance_km:float,source:string}|null
     */
    private function determineBaseLocationForDay(array $summary, ?array $nextSummary, array $home): ?array
    {
        $timezone = $this->resolveSummaryTimezone($summary, $home);

        $staypointBase = $this->selectStaypointBase($summary, $nextSummary, $timezone, $home);
        $sleepProxy    = $this->computeSleepProxyLocation($summary, $nextSummary, $home);

        if ($staypointBase !== null) {
            if ($staypointBase['distance_km'] > $home['radius_km']) {
                return $staypointBase;
            }

            if ($sleepProxy !== null && $sleepProxy['distance_km'] > $home['radius_km']) {
                return $sleepProxy;
            }

            return $staypointBase;
        }

        if ($sleepProxy !== null) {
            if ($sleepProxy['distance_km'] > $home['radius_km']) {
                return $sleepProxy;
            }

            $largestStaypoint = $this->selectLargestStaypoint($summary['staypoints'], $home);
            if ($largestStaypoint !== null) {
                return $largestStaypoint;
            }

            return $sleepProxy;
        }

        $largestStaypoint = $this->selectLargestStaypoint($summary['staypoints'], $home);
        if ($largestStaypoint !== null) {
            return $largestStaypoint;
        }

        return $this->fallbackBaseLocation($summary, $home);
    }

    /**
     * @param array{date:string,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>} $summary
     * @param array{date:string,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>}|null $nextSummary
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     *
     * @return array{lat:float,lon:float,distance_km:float,source:string}|null
     */
    private function selectStaypointBase(
        array $summary,
        ?array $nextSummary,
        DateTimeZone $timezone,
        array $home,
    ): ?array {
        $windowStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $summary['date'] . ' 18:00:00', $timezone);
        if ($windowStart === false) {
            return null;
        }

        $windowEnd = $windowStart->modify('+16 hours');

        $candidates = [];
        foreach ($summary['staypoints'] as $staypoint) {
            if ($this->staypointOverlapsWindow($staypoint, $windowStart, $windowEnd)) {
                $candidates[] = $staypoint;
            }
        }

        if ($nextSummary !== null) {
            foreach ($nextSummary['staypoints'] as $staypoint) {
                if ($this->staypointOverlapsWindow($staypoint, $windowStart, $windowEnd)) {
                    $candidates[] = $staypoint;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['dwell'] <=> $a['dwell']);
        $best = $candidates[0];

        return [
            'lat'          => $best['lat'],
            'lon'          => $best['lon'],
            'distance_km'  => $this->distanceToHomeKm($best['lat'], $best['lon'], $home),
            'source'       => 'staypoint',
        ];
    }

    /**
     * @param array{lat:float,lon:float,start:int,end:int} $staypoint
     */
    private function staypointOverlapsWindow(
        array $staypoint,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): bool {
        return $staypoint['end'] >= $windowStart->getTimestamp()
            && $staypoint['start'] <= $windowEnd->getTimestamp();
    }

    /**
     * @return array{lat:float,lon:float,distance_km:float,source:string}|null
     */
    private function computeSleepProxyLocation(array $summary, ?array $nextSummary, array $home): ?array
    {
        $last      = $summary['lastGpsMedia'];
        $nextFirst = $nextSummary['firstGpsMedia'] ?? null;

        if ($last instanceof Media && $nextFirst instanceof Media) {
            $lastCoords = $this->mediaCoordinates($last);
            $nextCoords = $this->mediaCoordinates($nextFirst);

            $pairDistance = MediaMath::haversineDistanceInMeters(
                $lastCoords['lat'],
                $lastCoords['lon'],
                $nextCoords['lat'],
                $nextCoords['lon'],
            ) / 1000.0;

            $lastDistance = $this->distanceToHomeKm($lastCoords['lat'], $lastCoords['lon'], $home);
            $nextDistance = $this->distanceToHomeKm($nextCoords['lat'], $nextCoords['lon'], $home);

            if ($pairDistance <= 2.0 && $lastDistance > $home['radius_km'] && $nextDistance > $home['radius_km']) {
                return [
                    'lat'         => ($lastCoords['lat'] + $nextCoords['lat']) / 2.0,
                    'lon'         => ($lastCoords['lon'] + $nextCoords['lon']) / 2.0,
                    'distance_km' => max($lastDistance, $nextDistance),
                    'source'      => 'sleep_proxy_pair',
                ];
            }

            if ($lastDistance > $nextDistance) {
                return [
                    'lat'         => $lastCoords['lat'],
                    'lon'         => $lastCoords['lon'],
                    'distance_km' => $lastDistance,
                    'source'      => 'sleep_proxy_last',
                ];
            }

            return [
                'lat'         => $nextCoords['lat'],
                'lon'         => $nextCoords['lon'],
                'distance_km' => $nextDistance,
                'source'      => 'sleep_proxy_first',
            ];
        }

        if ($last instanceof Media) {
            $coords = $this->mediaCoordinates($last);

            return [
                'lat'         => $coords['lat'],
                'lon'         => $coords['lon'],
                'distance_km' => $this->distanceToHomeKm($coords['lat'], $coords['lon'], $home),
                'source'      => 'sleep_proxy_last',
            ];
        }

        if ($nextFirst instanceof Media) {
            $coords = $this->mediaCoordinates($nextFirst);

            return [
                'lat'         => $coords['lat'],
                'lon'         => $coords['lon'],
                'distance_km' => $this->distanceToHomeKm($coords['lat'], $coords['lon'], $home),
                'source'      => 'sleep_proxy_first',
            ];
        }

        return null;
    }

    /**
     * @return array{lat:float,lon:float}
     */
    private function mediaCoordinates(Media $media): array
    {
        return [
            'lat' => (float) $media->getGpsLat(),
            'lon' => (float) $media->getGpsLon(),
        ];
    }

    /**
     * @param list<array{lat:float,lon:float,start:int,end:int,dwell:int}> $staypoints
     * @param array{lat:float,lon:float,radius_km:float} $home
     *
     * @return array{lat:float,lon:float,distance_km:float,source:string}|null
     */
    private function selectLargestStaypoint(array $staypoints, array $home): ?array
    {
        if ($staypoints === []) {
            return null;
        }

        usort($staypoints, static fn (array $a, array $b): int => $b['dwell'] <=> $a['dwell']);
        $best = $staypoints[0];

        return [
            'lat'         => $best['lat'],
            'lon'         => $best['lon'],
            'distance_km' => $this->distanceToHomeKm($best['lat'], $best['lon'], $home),
            'source'      => 'staypoint',
        ];
    }

    /**
     * @param array{gpsMembers:list<Media>} $summary
     * @param array{lat:float,lon:float,radius_km:float} $home
     *
     * @return array{lat:float,lon:float,distance_km:float,source:string}|null
     */
    private function fallbackBaseLocation(array $summary, array $home): ?array
    {
        $gpsMembers = $summary['gpsMembers'];
        if ($gpsMembers === []) {
            return null;
        }

        $centroid = MediaMath::centroid($gpsMembers);

        return [
            'lat'         => (float) $centroid['lat'],
            'lon'         => (float) $centroid['lon'],
            'distance_km' => $this->distanceToHomeKm((float) $centroid['lat'], (float) $centroid['lon'], $home),
            'source'      => 'day_centroid',
        ];
    }

    private function distanceToHomeKm(float $lat, float $lon, array $home): float
    {
        return MediaMath::haversineDistanceInMeters(
            $lat,
            $lon,
            $home['lat'],
            $home['lon'],
        ) / 1000.0;
    }

    /**
     * @param array<string, bool> $flags
     *
     * @return array<string, bool>
     */
    private function applyMorphologicalClosing(array $flags): array
    {
        $keys  = array_keys($flags);
        $count = count($keys);

        if ($count < 3) {
            return $flags;
        }

        for ($i = 1; $i < $count - 1; ++$i) {
            $prev = $flags[$keys[$i - 1]];
            $curr = $flags[$keys[$i]];
            $next = $flags[$keys[$i + 1]];

            if ($curr === false && $prev === true && $next === true) {
                $flags[$keys[$i]] = true;
            }
        }

        return $flags;
    }

    /**
     * @param array<string, bool> $flags
     *
     * @return array<string, bool>
     */
    private function propagateDistanceRuns(array $flags): array
    {
        $keys  = array_keys($flags);
        $count = count($keys);

        $first = null;
        $last  = null;
        foreach ($keys as $index => $key) {
            if ($flags[$key] === true) {
                $first ??= $index;
                $last = $index;
            }
        }

        if ($first !== null && $last !== null && $last > $first) {
            for ($i = $first; $i <= $last; ++$i) {
                $flags[$keys[$i]] = true;
            }
        }

        return $this->applyMorphologicalClosing($flags);
    }

    /**
     * @param array<string, bool> $flags
     * @param list<string>        $orderedKeys
     * @param array<string, array{isSynthetic:bool}> $days
     *
     * @return array<string, bool>
     */
    private function inheritSyntheticAwayFlags(array $flags, array $orderedKeys, array $days): array
    {
        $count = count($orderedKeys);

        for ($i = 0; $i < $count; ++$i) {
            $key = $orderedKeys[$i];
            $summary = $days[$key];

            if ($summary['isSynthetic'] === false) {
                continue;
            }

            $prev = $i > 0 ? $flags[$orderedKeys[$i - 1]] : null;
            $next = $i + 1 < $count ? $flags[$orderedKeys[$i + 1]] : null;

            if ($prev === true || $next === true) {
                $flags[$key] = true;
            }
        }

        return $flags;
    }

    /**
     * @param list<int> $values
     *
     * @return array{mean: float, std: float}
     */
    private function computeMeanStd(array $values): array
    {
        $count = count($values);
        if ($count === 0) {
            return ['mean' => 0.0, 'std' => 0.0];
        }

        $sum  = array_sum($values);
        $mean = $sum / $count;

        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        $std = sqrt($variance / $count);

        return ['mean' => $mean, 'std' => $std];
    }

    /**
     * @param array<int, int> $offsetVotes
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     */
    private function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int
    {
        if ($offsetVotes !== []) {
            $bestOffset = null;
            $bestCount  = -1;
            foreach ($offsetVotes as $offset => $count) {
                if ($count > $bestCount) {
                    $bestCount  = $count;
                    $bestOffset = (int) $offset;
                }
            }

            if ($bestOffset !== null) {
                return $bestOffset;
            }
        }

        return $home['timezone_offset'] ?? null;
    }

    /**
     * @param array<string, int> $identifierVotes
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     */
    private function determineLocalTimezoneIdentifier(
        array $identifierVotes,
        array $home,
        ?int $offset,
    ): string {
        if ($identifierVotes !== []) {
            $bestIdentifier = null;
            $bestCount      = -1;
            foreach ($identifierVotes as $identifier => $count) {
                if ($count > $bestCount && is_string($identifier) && $identifier !== '') {
                    $bestCount      = $count;
                    $bestIdentifier = $identifier;
                }
            }

            if ($bestIdentifier !== null) {
                return $bestIdentifier;
            }
        }

        if ($offset !== null) {
            return $this->createTimezoneFromOffset($offset)->getName();
        }

        $homeOffset = $home['timezone_offset'] ?? null;
        if ($homeOffset !== null) {
            return $this->createTimezoneFromOffset($homeOffset)->getName();
        }

        return $this->timezone;
    }

    private function isPoiSample(Location $location): bool
    {
        $pois = $location->getPois();
        if (!is_array($pois)) {
            return false;
        }

        foreach ($pois as $poi) {
            if (!is_array($poi)) {
                continue;
            }

            if (isset($poi['categoryKey']) && is_string($poi['categoryKey']) && $poi['categoryKey'] !== '') {
                return true;
            }

            if (isset($poi['categoryValue']) && is_string($poi['categoryValue']) && $poi['categoryValue'] !== '') {
                return true;
            }

            $tags = $poi['tags'] ?? null;
            if (!is_array($tags)) {
                continue;
            }

            if ($tags !== []) {
                return true;
            }
        }

        if ($this->matchesKeyword($location->getCategory(), self::TOURISM_KEYWORDS)) {
            return true;
        }

        if ($this->matchesKeyword($location->getType(), self::TOURISM_KEYWORDS)) {
            return true;
        }

        return $location->getType() !== null;
    }

    private function isTourismPoi(Location $location): bool
    {
        if ($this->matchesKeyword($location->getCategory(), self::TOURISM_KEYWORDS)) {
            return true;
        }

        if ($this->matchesKeyword($location->getType(), self::TOURISM_KEYWORDS)) {
            return true;
        }

        $pois = $location->getPois();
        if (!is_array($pois)) {
            return false;
        }

        foreach ($pois as $poi) {
            if (!is_array($poi)) {
                continue;
            }

            $categoryKey   = $poi['categoryKey'] ?? null;
            $categoryValue = $poi['categoryValue'] ?? null;
            if ($this->matchesKeyword($categoryKey, self::TOURISM_KEYWORDS)) {
                return true;
            }

            if ($this->matchesKeyword($categoryValue, self::TOURISM_KEYWORDS)) {
                return true;
            }

            $tags = $poi['tags'] ?? null;
            if (!is_array($tags)) {
                continue;
            }

            foreach ($tags as $tagKey => $tagValue) {
                if ($this->matchesKeyword(is_string($tagKey) ? $tagKey : null, self::TOURISM_KEYWORDS)) {
                    return true;
                }

                if ($this->matchesKeyword(is_string($tagValue) ? $tagValue : null, self::TOURISM_KEYWORDS)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isTransportPoi(Location $location): bool
    {
        if ($this->matchesKeyword($location->getCategory(), self::TRANSPORT_KEYWORDS)) {
            return true;
        }

        if ($this->matchesKeyword($location->getType(), self::TRANSPORT_KEYWORDS)) {
            return true;
        }

        $pois = $location->getPois();
        if (!is_array($pois)) {
            return false;
        }

        foreach ($pois as $poi) {
            if (!is_array($poi)) {
                continue;
            }

            $categoryKey   = $poi['categoryKey'] ?? null;
            $categoryValue = $poi['categoryValue'] ?? null;
            if ($this->matchesKeyword($categoryKey, self::TRANSPORT_KEYWORDS)) {
                return true;
            }

            if ($this->matchesKeyword($categoryValue, self::TRANSPORT_KEYWORDS)) {
                return true;
            }

            $tags = $poi['tags'] ?? null;
            if (!is_array($tags)) {
                continue;
            }

            foreach ($tags as $tagKey => $tagValue) {
                if ($this->matchesKeyword(is_string($tagKey) ? $tagKey : null, self::TRANSPORT_KEYWORDS)) {
                    return true;
                }

                if ($this->matchesKeyword(is_string($tagValue) ? $tagValue : null, self::TRANSPORT_KEYWORDS)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $keywords
     */
    private function matchesKeyword(?string $value, array $keywords): bool
    {
        if ($value === null) {
            return false;
        }

        $needle = strtolower($value);
        foreach ($keywords as $keyword) {
            $keywordLower = strtolower($keyword);
            if ($needle === $keywordLower) {
                return true;
            }

            if (str_contains($needle, $keywordLower)) {
                return true;
            }
        }

        return false;
    }
}
