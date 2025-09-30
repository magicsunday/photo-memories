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
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Contract\VacationSegmentAssemblerInterface;
use MagicSunday\Memories\Clusterer\Support\ConsecutiveDaysTrait;
use MagicSunday\Memories\Clusterer\Support\VacationTimezoneTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Scoring\HolidayResolverInterface;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function array_keys;
use function array_map;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function log;
use function max;
use function min;
use function preg_replace;
use function round;
use function sort;
use function sprintf;
use function str_replace;
use function strtolower;
use function ucwords;
use function usort;

use const SORT_NUMERIC;
use const SORT_STRING;

/**
 * Default assembler that turns vacation day summaries into scored segments.
 */
final class DefaultVacationSegmentAssembler implements VacationSegmentAssemblerInterface
{
    use ConsecutiveDaysTrait;
    use VacationTimezoneTrait;

    private const float WEEKEND_OR_HOLIDAY_BONUS = 0.35;

    public function __construct(
        private LocationHelper $locationHelper,
        private HolidayResolverInterface $holidayResolver = new NullHolidayResolver(),
        private string $timezone = 'Europe/Berlin',
        private float $minAwayDistanceKm = 120.0,
        private float $movementThresholdKm = 35.0,
        private int $minItemsPerDay = 3,
    ) {
        if ($this->timezone === '') {
            throw new InvalidArgumentException('timezone must not be empty.');
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
    }

    /**
     * @param array<string, array{date:string,members:list<Media>,gpsMembers:list<Media>,maxDistanceKm:float,avgDistanceKm:float,travelKm:float,countryCodes:array<string,true>,timezoneOffsets:array<int,int>,localTimezoneIdentifier:string,localTimezoneOffset:int|null,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,isAwayCandidate:bool,sufficientSamples:bool,spotClusters:list<list<Media>>,spotNoise:list<Media>,spotCount:int,spotNoiseSamples:int,spotDwellSeconds:int,staypoints:list<array{lat:float,lon:float,start:int,end:int,dwell:int}>,baseLocation:array{lat:float,lon:float,distance_km:float,source:string}|null,baseAway:bool,awayByDistance:bool,firstGpsMedia:Media|null,lastGpsMedia:Media|null,isSynthetic:bool}> $days
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     *
     * @return list<ClusterDraft>
     */
    public function detectSegments(array $days, array $home): array
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
            $isCandidate = $summary['baseAway'];

            if ($isCandidate === false && $summary['gpsMembers'] !== []) {
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
                if ($this->areSequentialDays($last, $key, $days) === false) {
                    $flush();
                }
            }

            $run[] = $key;
        }

        $flush();

        return $clusters;
    }

    /**
     * @param list<string>                           $run
     * @param list<string>                           $orderedKeys
     * @param array<string, int>                     $indexByKey
     * @param array<string, array{hasAirportPoi:bool,isSynthetic:bool}> $days
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
                && $this->areSequentialDays($candidateKey, $firstKey, $days)
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
                && $this->areSequentialDays($lastKey, $candidateKey, $days)
            ) {
                $extended[] = $candidateKey;
            }
        }

        return $extended;
    }

    /**
     * @param list<string> $dayKeys
     * @param array<string, array{date:string,members:list<Media>,gpsMembers:list<Media>,maxDistanceKm:float,avgDistanceKm:float,travelKm:float,countryCodes:array<string,true>,timezoneOffsets:array<int,int>,localTimezoneIdentifier:string,localTimezoneOffset:int|null,tourismHits:int,poiSamples:int,tourismRatio:float,hasAirportPoi:bool,weekday:int,photoCount:int,densityZ:float,isAwayCandidate:bool,sufficientSamples:bool,spotClusters:list<list<Media>>,spotNoise:list<Media>,spotCount:int,spotNoiseSamples:int,spotDwellSeconds:int,baseAway:bool,baseLocation:array{lat:float,lon:float,distance_km:float,source:string}|null,isSynthetic:bool}> $days
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
        $photoDensityDenominator = 0;
        $timezoneOffsets = [];
        $countryCodes = [];
        $workDayPenalty = 0;
        $reliableDays = 0;
        $spotClusterCount = 0;
        $multiSpotDays = 0;
        $spotDwellSeconds = 0;
        $weekendHolidayDays = 0;
        $awayDays = 0;

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
            if ($summary['baseAway']) {
                $tourismHits += $summary['tourismHits'];
                $poiSamples += $summary['poiSamples'];

                if ($summary['travelKm'] > $this->movementThresholdKm) {
                    ++$moveDays;
                }

                $photoDensitySum += $summary['densityZ'];
                ++$photoDensityDenominator;
            }

            foreach ($summary['timezoneOffsets'] as $offset => $count) {
                if (!isset($timezoneOffsets[$offset])) {
                    $timezoneOffsets[$offset] = 0;
                }

                $timezoneOffsets[$offset] += $count;
            }

            foreach ($summary['countryCodes'] as $code => $value) {
                if ($value === true) {
                    $countryCodes[$code] = true;
                }
            }

            if ($summary['baseAway']) {
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

                ++$awayDays;
            }

            $dayTimezone = $this->resolveSummaryTimezone($summary, $home);
            $dayDate     = new DateTimeImmutable($summary['date'], $dayTimezone);
            $isWeekend = $summary['weekday'] >= 6;
            $isHoliday = $this->holidayResolver->isHoliday($dayDate);

            if ($summary['baseAway'] && ($isWeekend || $isHoliday)) {
                ++$weekendHolidayDays;
            }
        }

        if ($awayDays === 0 || $reliableDays === 0) {
            return null;
        }

        if ($gpsMembers === []) {
            return null;
        }

        $dayCount = count($dayKeys);
        $avgDistance = $avgDistanceSum / $dayCount;

        $centroid = MediaMath::centroid($gpsMembers);
        $centroidDistanceKm = MediaMath::haversineDistanceInMeters(
            $home['lat'],
            $home['lon'],
            (float) $centroid['lat'],
            (float) $centroid['lon'],
        ) / 1000.0;

        $countries = [];
        if ($countryCodes !== []) {
            $countries = array_keys($countryCodes);
            sort($countries, SORT_STRING);
        }

        $timezones = [];
        if ($timezoneOffsets !== []) {
            $timezones = array_keys($timezoneOffsets);
            sort($timezones, SORT_NUMERIC);
        }

        $tourismRatio = $poiSamples > 0 ? min(1.0, $tourismHits / max(1, $poiSamples)) : 0.0;
        $photoDensityZ = $photoDensityDenominator > 0 ? $photoDensitySum / $photoDensityDenominator : 0.0;

        $firstDay = $days[$dayKeys[0]];
        $lastDay  = $days[$dayKeys[$dayCount - 1]];
        $airportFlag = $firstDay['hasAirportPoi'] || $lastDay['hasAirportPoi'];

        $countryChange = $countries !== [] && (count($countries) > 1 || ($home['country'] !== null && !in_array($home['country'], $countries, true)));
        $timezoneChange = $timezones !== [] && (count($timezones) > 1 || ($home['timezone_offset'] !== null && !in_array($home['timezone_offset'], $timezones, true)));

        $spotDwellHours = $spotDwellSeconds / 3600.0;
        $multiSpotBonus = min(3.0, $multiSpotDays * 0.9);
        $dwellBonus     = min(1.5, $spotDwellHours * 0.3);
        $spotBonus      = $multiSpotBonus + $dwellBonus;
        $weekendHolidayBonus = min(2.0, $weekendHolidayDays * self::WEEKEND_OR_HOLIDAY_BONUS);

        $awayDayScore   = min(10, $awayDays) * 1.6;
        $distanceScore  = $centroidDistanceKm > 0.0 ? 1.2 * log(1.0 + $centroidDistanceKm) : 0.0;
        $countryBonus   = $countryChange ? 2.5 : 0.0;
        $timezoneBonus  = $timezoneChange ? 2.0 : 0.0;
        $tourismBonus   = 1.5 * $tourismRatio;
        $moveBonus      = 0.8 * $moveDays;
        $airportBonus   = $airportFlag ? 1.0 : 0.0;
        $densityBonus   = 0.6 * $photoDensityZ;
        $explorationBonus = $spotBonus;
        $weekendHolidayScore = $weekendHolidayBonus;
        $penalty        = 0.4 * $workDayPenalty;

        $score = $awayDayScore
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

        $memberIds = array_map(
            static fn (Media $media): int => $media->getId(),
            $members
        );

        $place = $this->locationHelper->majorityLabel($members);
        $placeComponents = $this->locationHelper->majorityLocationComponents($members);

        $classificationLabels = [
            'vacation'   => 'Urlaub',
            'short_trip' => 'Kurztrip',
            'day_trip'   => 'Tagesausflug',
        ];

        $params = [
            'classification'       => $classification,
            'classification_label' => $classificationLabels[$classification] ?? 'Reise',
            'score'                => round($score, 2),
            'nights'               => max(0, $awayDays - 1),
            'away_days'            => $awayDays,
            'total_days'           => $dayCount,
            'time_range'           => $timeRange,
            'max_distance_km'      => $centroidDistanceKm,
            'max_observed_distance_km' => $maxDistance,
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
            'work_day_penalty_days' => $workDayPenalty,
            'work_day_penalty_score' => round($penalty, 2),
            'countries'            => $countries,
            'timezones'            => $timezones,
        ];

        if ($placeComponents !== []) {
            $city    = $placeComponents['city'] ?? null;
            $region  = $placeComponents['region'] ?? null;
            $country = $placeComponents['country'] ?? null;

            $locationParts = [];

            if ($city !== null) {
                $cityLabel = $this->formatLocationComponent($city);
                if ($cityLabel !== '') {
                    $params['place_city'] = $cityLabel;
                    $locationParts[] = $cityLabel;
                }
            }

            if ($region !== null) {
                $regionLabel = $this->formatLocationComponent($region);
                if ($regionLabel !== '') {
                    $params['place_region'] = $regionLabel;
                    if (!in_array($regionLabel, $locationParts, true)) {
                        $locationParts[] = $regionLabel;
                    }
                }
            }

            if ($country !== null) {
                $countryLabel = $this->formatLocationComponent($country);
                if ($countryLabel !== '') {
                    $params['place_country'] = $countryLabel;
                    if (!in_array($countryLabel, $locationParts, true)) {
                        $locationParts[] = $countryLabel;
                    }
                }
            }

            if ($locationParts !== []) {
                $params['place_location'] = implode(', ', $locationParts);
            }
        }

        if ($place !== null) {
            $params['place'] = $place;
        }

        return new ClusterDraft(
            algorithm: 'vacation',
            params: $params,
            centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
            members: $memberIds,
        );
    }
    private function formatLocationComponent(string $value): string
    {
        $value = str_replace('_', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        $parts = explode('-', $value);
        foreach ($parts as $index => $part) {
            $parts[$index] = ucwords($part);
        }

        return implode('-', $parts);
    }

    /**
     * @param array<string, array{isSynthetic:bool}> $days
     */
    private function areSequentialDays(string $previous, string $current, array $days): bool
    {
        if ($this->isNextDay($previous, $current)) {
            return true;
        }

        $timezone = new DateTimeZone('UTC');
        $start    = DateTimeImmutable::createFromFormat('!Y-m-d', $previous, $timezone);
        $end      = DateTimeImmutable::createFromFormat('!Y-m-d', $current, $timezone);

        if ($start === false || $end === false || $start > $end) {
            return false;
        }

        $cursor = $start->modify('+1 day');
        while ($cursor < $end) {
            $key = $cursor->format('Y-m-d');
            $summary = $days[$key] ?? null;
            if ($summary === null) {
                return false;
            }

            if (($summary['isSynthetic'] ?? false) === false) {
                return false;
            }

            $cursor = $cursor->modify('+1 day');
        }

        return true;
    }
}
