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
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Clusterer\Support\GeoDbscanHelper;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Scoring\HolidayResolverInterface;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function array_keys;
use function array_map;
use function array_sum;
use function array_values;
use function assert;
use function count;
use function in_array;
use function intdiv;
use function is_array;
use function is_string;
use function log;
use function max;
use function min;
use function round;
use function sort;
use function sprintf;
use function sqrt;
use function str_contains;
use function strtolower;
use function usort;

use const SORT_STRING;

/**
 * Scores multi-day absences from home and classifies them into vacation-like clusters.
 */
final readonly class VacationClusterStrategy implements ClusterStrategyInterface
{
    use ConsecutiveDaysTrait;
    use MediaFilterTrait;

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

    private const int NIGHT_START_HOUR = 22;

    private const int NIGHT_END_HOUR = 6;

    private const float MIN_STD_EPSILON = 1.0e-6;

    private const float WEEKEND_OR_HOLIDAY_BONUS = 0.35;

    public function __construct(
        private LocationHelper $locationHelper = new LocationHelper(),
        private HolidayResolverInterface $holidayResolver = new NullHolidayResolver(),
        private string $timezone = 'Europe/Berlin',
        private float $defaultHomeRadiusKm = 15.0,
        private float $minAwayDistanceKm = 120.0,
        private float $movementThresholdKm = 35.0,
        private int $minItemsPerDay = 3,
        private float $gpsOutlierRadiusKm = 1.0,
        private int $gpsOutlierMinSamples = 3,
        private ?float $homeLat = null,
        private ?float $homeLon = null,
        private ?float $homeRadiusKm = null,
        private GeoDbscanHelper $dbscanHelper = new GeoDbscanHelper(),
    ) {
        if ($this->timezone === '') {
            throw new InvalidArgumentException('timezone must not be empty.');
        }

        if ($this->defaultHomeRadiusKm <= 0.0) {
            throw new InvalidArgumentException('defaultHomeRadiusKm must be > 0.');
        }

        if ($this->minAwayDistanceKm <= 0.0) {
            throw new InvalidArgumentException('minAwayDistanceKm must be > 0.');
        }

        if ($this->movementThresholdKm <= 0.0) {
            throw new InvalidArgumentException('movementThresholdKm must be > 0.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        if ($this->gpsOutlierRadiusKm <= 0.0) {
            throw new InvalidArgumentException('gpsOutlierRadiusKm must be > 0.');
        }

        if ($this->gpsOutlierMinSamples < 2) {
            throw new InvalidArgumentException('gpsOutlierMinSamples must be >= 2.');
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

    public function name(): string
    {
        return 'vacation';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $timestamped = $this->filterTimestampedItems($items);
        if ($timestamped === []) {
            return [];
        }

        $home = $this->determineHome($timestamped);
        if ($home === null) {
            return [];
        }

        $days = $this->buildDaySummaries($timestamped, $home);
        if ($days === []) {
            return [];
        }

        return $this->detectSegments($days, $home);
    }

    /**
     * @param list<Media> $items
     *
     * @return array{
     *     lat: float,
     *     lon: float,
     *     radius_km: float,
     *     country: string|null,
     *     timezone_offset: int|null
     * }|null
     */
    private function determineHome(array $items): ?array
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
         * @var array<string, array{members:list<Media>, countryCounts:array<string,int>, offsets:array<int,int>}>
         */
        $clusters = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            assert($takenAt instanceof DateTimeImmutable);
            $local = $takenAt->setTimezone($tz);
            $hour  = (int) $local->format('G');

            if ($hour < self::NIGHT_START_HOUR && $hour >= self::NIGHT_END_HOUR) {
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
            $distance = MediaMath::haversineDistanceInMeters(
                (float) $media->getGpsLat(),
                (float) $media->getGpsLon(),
                (float) $centroid['lat'],
                (float) $centroid['lon'],
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
            'lat'             => (float) $centroid['lat'],
            'lon'             => (float) $centroid['lon'],
            'radius_km'       => max($maxDistance, $this->defaultHomeRadiusKm),
            'country'         => $country,
            'timezone_offset' => $timezoneOffset,
        ];
    }

    /**
     * @param list<Media>                                                   $items
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     *
     * @return array<string, array{
     *     date: string,
     *     members: list<Media>,
     *     gpsMembers: list<Media>,
     *     nightGps: list<Media>,
     *     maxDistanceKm: float,
     *     avgDistanceKm: float,
     *     travelKm: float,
     *     countryCodes: array<string, true>,
     *     timezoneOffsets: array<int, true>,
     *     tourismHits: int,
     *     poiSamples: int,
     *     tourismRatio: float,
     *     hasAirportPoi: bool,
     *     weekday: int,
     *     photoCount: int,
     *     densityZ: float,
     *     nightAway: bool,
     *     isAwayCandidate: bool,
     *     sufficientSamples: bool
     * }>
     */
    private function buildDaySummaries(array $items, array $home): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var array<string, array{date:string,members:list<Media>,gpsMembers:list<Media>,nightGps:list<Media>,maxDistanceKm:float,distanceSum:float,distanceCount:int,avgDistanceKm:float,travelKm:float,countryCodes:array<string,true>,timezoneOffsets:array<int,true>,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,nightAway:bool,isAwayCandidate:bool,sufficientSamples:bool,spotClusters:list<list<Media>>,spotNoise:list<Media>,spotCount:int,spotNoiseSamples:int,spotDwellSeconds:int}> $days */
        $days = [];

        foreach ($items as $media) {
            $takenAt = $media->getTakenAt();
            assert($takenAt instanceof DateTimeImmutable);
            $local = $takenAt->setTimezone($tz);
            $date  = $local->format('Y-m-d');

            if (!isset($days[$date])) {
                $days[$date] = [
                    'date'              => $date,
                    'members'           => [],
                    'gpsMembers'        => [],
                    'nightGps'          => [],
                    'maxDistanceKm'     => 0.0,
                    'distanceSum'       => 0.0,
                    'distanceCount'     => 0,
                    'avgDistanceKm'     => 0.0,
                    'travelKm'          => 0.0,
                    'countryCodes'      => [],
                    'timezoneOffsets'   => [],
                    'tourismHits'       => 0,
                    'poiSamples'        => 0,
                    'tourismRatio'      => 0.0,
                    'hasAirportPoi'     => false,
                    'weekday'           => (int) $local->format('N'),
                    'photoCount'        => 0,
                    'densityZ'          => 0.0,
                    'nightAway'         => false,
                    'isAwayCandidate'   => false,
                    'sufficientSamples' => false,
                    'spotClusters'      => [],
                    'spotNoise'         => [],
                    'spotCount'         => 0,
                    'spotNoiseSamples'  => 0,
                    'spotDwellSeconds'  => 0,
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

            $offset = $media->getTimezoneOffsetMin();
            if ($offset !== null) {
                $summary['timezoneOffsets'][$offset] = true;
            }

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
        foreach ($days as $date => &$summary) {
            $summary['gpsMembers'] = $this->filterGpsOutliers(
                $summary['gpsMembers'],
                $this->gpsOutlierRadiusKm,
                $this->gpsOutlierMinSamples,
            );

            $summary['nightGps']        = [];
            $summary['maxDistanceKm']   = 0.0;
            $summary['distanceSum']     = 0.0;
            $summary['distanceCount']   = 0;
            $summary['avgDistanceKm']   = 0.0;
            $summary['travelKm']        = 0.0;
            $summary['nightAway']       = false;

            $gpsMembers = $summary['gpsMembers'];
            if ($gpsMembers !== []) {
                usort($gpsMembers, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

                $travelKm = 0.0;
                $previous = null;

                foreach ($gpsMembers as $gpsMedia) {
                    $lat = $gpsMedia->getGpsLat();
                    $lon = $gpsMedia->getGpsLon();
                    $takenAt = $gpsMedia->getTakenAt();

                    assert($lat !== null && $lon !== null);
                    assert($takenAt instanceof DateTimeImmutable);

                    $distanceKm = MediaMath::haversineDistanceInMeters(
                        (float) $lat,
                        (float) $lon,
                        $home['lat'],
                        $home['lon'],
                    ) / 1000.0;

                    if ($distanceKm > $summary['maxDistanceKm']) {
                        $summary['maxDistanceKm'] = $distanceKm;
                    }

                    $summary['distanceSum'] += $distanceKm;
                    ++$summary['distanceCount'];

                    $local = $takenAt->setTimezone($tz);
                    $hour  = (int) $local->format('G');
                    if ($hour >= self::NIGHT_START_HOUR || $hour < self::NIGHT_END_HOUR) {
                        $summary['nightGps'][] = $gpsMedia;
                    }

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

                $summary['gpsMembers'] = $gpsMembers;
                $summary['travelKm']    = $travelKm;

                if ($summary['distanceCount'] > 0) {
                    $summary['avgDistanceKm'] = $summary['distanceSum'] / $summary['distanceCount'];
                }

                if ($summary['nightGps'] !== []) {
                    $centroid = MediaMath::centroid($summary['nightGps']);
                    $nightDistance = MediaMath::haversineDistanceInMeters(
                        (float) $centroid['lat'],
                        (float) $centroid['lon'],
                        $home['lat'],
                        $home['lon'],
                    ) / 1000.0;

                    $summary['nightAway'] = $nightDistance > $home['radius_km'];
                }

                $clusters = $this->dbscanHelper->clusterMedia(
                    $summary['gpsMembers'],
                    0.1,
                    $this->gpsOutlierMinSamples,
                );

                $summary['spotClusters'] = $clusters['clusters'];
                $summary['spotNoise']    = $clusters['noise'];
                $summary['spotCount']    = count($clusters['clusters']);
                $summary['spotNoiseSamples'] = count($clusters['noise']);

                $dwellSeconds = 0;
                foreach ($summary['spotClusters'] as $clusterMembers) {
                    $range = MediaMath::timeRange($clusterMembers);
                    $span  = $range['to'] - $range['from'];
                    $dwellSeconds += max(0, $span);
                }

                $summary['spotDwellSeconds'] = $dwellSeconds;
            }

            if ($summary['poiSamples'] > 0) {
                $summary['tourismRatio'] = min(1.0, $summary['tourismHits'] / (float) $summary['poiSamples']);
            }

            $summary['sufficientSamples'] = $summary['photoCount'] >= $this->minItemsPerDay;
        }

        unset($summary);

        $counts = array_map(
            static fn (array $day): int => $day['photoCount'],
            array_values($days)
        );

        $stats = $this->computeMeanStd($counts);
        foreach ($days as &$summary) {
            if ($stats['std'] > self::MIN_STD_EPSILON) {
                $summary['densityZ'] = ($summary['photoCount'] - $stats['mean']) / $stats['std'];
            } else {
                $summary['densityZ'] = 0.0;
            }
        }

        unset($summary);

        ksort($days, SORT_STRING);

        return $days;
    }

    /**
     * @param array<string, array{date:string,members:list<Media>,gpsMembers:list<Media>,nightGps:list<Media>,maxDistanceKm:float,avgDistanceKm:float,travelKm:float,countryCodes:array<string,true>,timezoneOffsets:array<int,true>,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,nightAway:bool,isAwayCandidate:bool,sufficientSamples:bool,spotClusters:list<list<Media>>,spotNoise:list<Media>,spotCount:int,spotNoiseSamples:int,spotDwellSeconds:int}> $days
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     *
     * @return list<ClusterDraft>
     */
    private function detectSegments(array $days, array $home): array
    {
        if ($days === []) {
            return [];
        }

        $keys = array_keys($days);
        $indexByKey = [];
        foreach ($keys as $index => $key) {
            $indexByKey[$key] = $index;
        }

        foreach ($keys as $key) {
            $summary = &$days[$key];
            $isCandidate = $summary['nightAway'];

            if ($isCandidate === false && $summary['gpsMembers'] !== []) {
                // Treat lightly-sampled days as valid when they still provide
                // enough points to confirm that the traveller stayed away from
                // home. This keeps thin capture days at the start/end of a trip
                // inside the segment instead of splitting the run.
                $hasUsefulSamples = $summary['sufficientSamples'] || $summary['photoCount'] >= 2;

                if ($summary['avgDistanceKm'] > $home['radius_km'] && $hasUsefulSamples) {
                    $isCandidate = true;
                } elseif ($summary['maxDistanceKm'] > $this->minAwayDistanceKm && $hasUsefulSamples) {
                    $isCandidate = true;
                }
            }

            $summary['isAwayCandidate'] = $isCandidate;
            unset($summary);
        }

        $countKeys = count($keys);
        for ($i = 0; $i < $countKeys; ++$i) {
            $key = $keys[$i];
            $summary = $days[$key];
            if ($summary['isAwayCandidate']) {
                continue;
            }

            $gpsMembers = $summary['gpsMembers'];
            if ($gpsMembers === [] || $summary['photoCount'] < $this->minItemsPerDay) {
                // Allow zero-GPS or sparse-photo days sandwiched between strong
                // away signals to ride along so that brief upload gaps do not
                // fragment the vacation run.
                $prevIsAway = $i > 0 && $days[$keys[$i - 1]]['isAwayCandidate'];
                $nextIsAway = $i + 1 < $countKeys && $days[$keys[$i + 1]]['isAwayCandidate'];
                if ($prevIsAway && $nextIsAway) {
                    $days[$key]['isAwayCandidate'] = true;
                }
            }
        }

        /** @var list<ClusterDraft> $clusters */
        $clusters = [];

        /** @var list<string> $run */
        $run = [];

        $flush = function () use (&$run, &$clusters, $days, $home, $keys, $indexByKey): void {
            if ($run === []) {
                return;
            }

            $expandedRun = $this->extendWithTransportDays($run, $keys, $indexByKey, $days);

            $draft = $this->buildClusterDraft($expandedRun, $days, $home);
            if ($draft instanceof ClusterDraft) {
                $clusters[] = $draft;
            }

            $run = [];
        };

        foreach ($keys as $key) {
            if ($days[$key]['isAwayCandidate'] === false) {
                $flush();
                continue;
            }

            if ($run !== []) {
                $last = $run[count($run) - 1];
                if ($this->isNextDay($last, $key) === false) {
                    $flush();
                }
            }

            $run[] = $key;
        }

        $flush();

        return $clusters;
    }

    /**
     * Ensures transport-heavy buffer days at the beginning or end of a run are
     * included in the final segment, as mandated by the vacation heuristic.
     *
     * @param list<string>               $run
     * @param list<string>               $orderedKeys
     * @param array<string, int>         $indexByKey
     * @param array<string, array<mixed>> $days Full per-day summaries; only the airport flag is accessed.
     *
     * @return list<string>
     */
    private function extendWithTransportDays(
        array $run,
        array $orderedKeys,
        array $indexByKey,
        array $days,
    ): array {
        if ($run === []) {
            return $run;
        }

        $extended = $run;

        $firstKey   = $run[0];
        $firstIndex = $indexByKey[$firstKey] ?? null;
        if ($firstIndex !== null && $firstIndex > 0) {
            $candidateKey = $orderedKeys[$firstIndex - 1];
            if (
                !in_array($candidateKey, $extended, true)
                && ($days[$candidateKey]['hasAirportPoi'] ?? false)
                && $this->isNextDay($candidateKey, $firstKey)
            ) {
                array_unshift($extended, $candidateKey);
            }
        }

        $lastKey   = $run[count($run) - 1];
        $lastIndex = $indexByKey[$lastKey] ?? null;
        $orderedCount = count($orderedKeys);
        if ($lastIndex !== null && $lastIndex + 1 < $orderedCount) {
            $candidateKey = $orderedKeys[$lastIndex + 1];
            if (
                !in_array($candidateKey, $extended, true)
                && ($days[$candidateKey]['hasAirportPoi'] ?? false)
                && $this->isNextDay($lastKey, $candidateKey)
            ) {
                $extended[] = $candidateKey;
            }
        }

        return $extended;
    }

    /**
     * @param list<string> $dayKeys
     * @param array<string, array{date:string,members:list<Media>,gpsMembers:list<Media>,nightGps:list<Media>,maxDistanceKm:float,avgDistanceKm:float,travelKm:float,countryCodes:array<string,true>,timezoneOffsets:array<int,true>,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,nightAway:bool,isAwayCandidate:bool,sufficientSamples:bool}> $days
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     */
    private function buildClusterDraft(array $dayKeys, array $days, array $home): ?ClusterDraft
    {
        if ($dayKeys === []) {
            return null;
        }

        $members = [];
        $gpsMembers = [];
        $maxDistance = 0.0;
        $avgDistanceSum = 0.0;
        $tourismHits = 0;
        $poiSamples = 0;
        $moveDays = 0;
        $photoDensitySum = 0.0;
        $timezoneOffsets = [];
        $countryCodes = [];
        $workDayPenalty = 0;
        $reliableDays = 0;
        $spotClusterCount = 0;
        $multiSpotDays = 0;
        $spotDwellSeconds = 0;
        $weekendHolidayDays = 0;
        $timezone = new DateTimeZone($this->timezone);

        foreach ($dayKeys as $key) {
            $summary = $days[$key];
            foreach ($summary['members'] as $media) {
                $members[] = $media;
            }

            foreach ($summary['gpsMembers'] as $gpsMedia) {
                $gpsMembers[] = $gpsMedia;
            }

            if ($summary['maxDistanceKm'] > $maxDistance) {
                $maxDistance = $summary['maxDistanceKm'];
            }

            $avgDistanceSum += $summary['avgDistanceKm'];
            $tourismHits += $summary['tourismHits'];
            $poiSamples += $summary['poiSamples'];

            if ($summary['travelKm'] > $this->movementThresholdKm) {
                ++$moveDays;
            }

            $photoDensitySum += $summary['densityZ'];

            foreach ($summary['timezoneOffsets'] as $offset => $value) {
                if ($value === true) {
                    $timezoneOffsets[$offset] = true;
                }
            }

            foreach ($summary['countryCodes'] as $code => $value) {
                if ($value === true) {
                    $countryCodes[$code] = true;
                }
            }

            if ($summary['weekday'] >= 1 && $summary['weekday'] <= 5 && $summary['tourismRatio'] < 0.2) {
                ++$workDayPenalty;
            }

            if ($summary['sufficientSamples'] && $summary['gpsMembers'] !== []) {
                ++$reliableDays;
            }

            $spotClusterCount += $summary['spotCount'];
            $spotDwellSeconds += $summary['spotDwellSeconds'];

            if ($summary['spotCount'] >= 2) {
                ++$multiSpotDays;
            }

            $dayDate = new DateTimeImmutable($summary['date'], $timezone);
            $isWeekend = $summary['weekday'] >= 6;
            $isHoliday = $this->holidayResolver->isHoliday($dayDate);

            if ($isWeekend || $isHoliday) {
                ++$weekendHolidayDays;
            }
        }

        if ($reliableDays === 0) {
            return null;
        }

        if ($gpsMembers === []) {
            return null;
        }
        $dayCount   = count($dayKeys);
        $awayNights = max(0, $dayCount - 1);
        $avgDistance = $dayCount > 0 ? $avgDistanceSum / $dayCount : 0.0;
        $tourismRatio = $poiSamples > 0 ? min(1.0, $tourismHits / (float) $poiSamples) : 0.0;
        $photoDensityZ = $dayCount > 0 ? $photoDensitySum / $dayCount : 0.0;

        $countryChange = false;
        if ($countryCodes !== []) {
            $homeCountry = $home['country'];
            if ($homeCountry !== null) {
                if (!isset($countryCodes[$homeCountry]) || count($countryCodes) > 1) {
                    $countryChange = true;
                }
            } elseif (count($countryCodes) > 1) {
                $countryChange = true;
            }
        }

        $timezoneChange = false;
        if ($timezoneOffsets !== []) {
            $homeOffset = $home['timezone_offset'];
            if ($homeOffset !== null) {
                foreach ($timezoneOffsets as $offset => $value) {
                    if ($value === true && $offset !== $homeOffset) {
                        $timezoneChange = true;
                        break;
                    }
                }
            } elseif (count($timezoneOffsets) > 1) {
                $timezoneChange = true;
            }
        }

        $airportFlag = false;
        $firstDay = $days[$dayKeys[0]];
        $lastDay  = $days[$dayKeys[$dayCount - 1]];
        if ($firstDay['hasAirportPoi'] || $lastDay['hasAirportPoi']) {
            $airportFlag = true;
        }

        $spotDwellHours = $spotDwellSeconds / 3600.0;
        $multiSpotBonus = min(3.0, $multiSpotDays * 0.9);
        $dwellBonus     = min(1.5, $spotDwellHours * 0.3);
        $spotBonus      = $multiSpotBonus + $dwellBonus;
        $weekendHolidayBonus = min(2.0, $weekendHolidayDays * self::WEEKEND_OR_HOLIDAY_BONUS);

        $awayNightScore = min(7, $awayNights) * 2.0;
        $distanceScore  = $maxDistance > 0.0 ? 1.2 * log(1.0 + $maxDistance) : 0.0;
        $countryBonus   = $countryChange ? 2.5 : 0.0;
        $timezoneBonus  = $timezoneChange ? 2.0 : 0.0;
        $tourismBonus   = 1.5 * $tourismRatio;
        $moveBonus      = 0.8 * $moveDays;
        $airportBonus   = $airportFlag ? 1.0 : 0.0;
        $densityBonus   = 0.6 * $photoDensityZ;
        $explorationBonus = $spotBonus;
        $weekendHolidayScore = $weekendHolidayBonus;
        $penalty        = 0.4 * $workDayPenalty;

        $score = $awayNightScore
            + $distanceScore
            + $countryBonus
            + $timezoneBonus
            + $tourismBonus
            + $moveBonus
            + $airportBonus
            + $densityBonus
            + $explorationBonus
            + $weekendHolidayScore
            - $penalty;

        $classification = 'none';
        if ($score >= 8.0) {
            $classification = 'vacation';
        } elseif ($score >= 6.0) {
            $classification = 'short_trip';
        } elseif ($score >= 4.0) {
            $classification = 'day_trip';
        }

        if ($classification === 'none') {
            return null;
        }

        usort($members, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $timeRange = MediaMath::timeRange($members);
        $centroid  = MediaMath::centroid($gpsMembers);

        $memberIds = array_map(
            static fn (Media $media): int => $media->getId(),
            $members
        );

        $place = $this->locationHelper->majorityLabel($members);

        $classificationLabels = [
            'vacation'   => 'Urlaub',
            'short_trip' => 'Kurztrip',
            'day_trip'   => 'Tagesausflug',
        ];

        $params = [
            'classification'       => $classification,
            'classification_label' => $classificationLabels[$classification] ?? 'Reise',
            'score'                => round($score, 2),
            'nights'               => $awayNights,
            'away_days'            => $dayCount,
            'time_range'           => $timeRange,
            'max_distance_km'      => $maxDistance,
            'avg_distance_km'      => $avgDistance,
            'country_change'       => $countryChange,
            'timezone_change'      => $timezoneChange,
            'tourism_ratio'        => $tourismRatio,
            'move_days'            => $moveDays,
            'photo_density_z'      => $photoDensityZ,
            'airport_transfer'     => $airportFlag,
            'spot_clusters_total'  => $spotClusterCount,
            'spot_cluster_days'    => $multiSpotDays,
            'spot_dwell_hours'     => round($spotDwellHours, 2),
            'spot_exploration_bonus' => round($explorationBonus, 2),
            'weekend_holiday_days' => $weekendHolidayDays,
            'weekend_holiday_bonus' => round($weekendHolidayBonus, 2),
        ];

        if ($place !== null) {
            $params['place'] = $place;
        }

        return new ClusterDraft(
            algorithm: $this->name(),
            params: $params,
            centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
            members: $memberIds,
        );
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
     * Builds a deterministic key for grouping potential home locations.
     *
     * Preference is given to high quality spatial information (cell, country),
     * falling back to rounded coordinates to keep clusters compact.
     */
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
            if ($country !== null) {
                return 'country:' . strtolower($country);
            }
        }

        return sprintf('geo:%0.3f|%0.3f', round($lat, 3), round($lon, 3));
    }
    /**
     * Determines whether the location contains enough structured information to
     * be counted as a POI sample for tourism ratio calculations.
     */
    private function isPoiSample(Location $location): bool
    {
        $pois = $location->getPois();
        if (is_array($pois) && $pois !== []) {
            return true;
        }

        if ($location->getCategory() !== null) {
            return true;
        }

        return $location->getType() !== null;
    }

    /**
     * Checks if the provided location is categorised as tourism related.
     */
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

    /**
     * Checks if the provided location is associated with major transport hubs.
     */
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
     * Performs a case-insensitive comparison against a keyword list, allowing
     * partial matches to capture rich POI tag values.
     *
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
