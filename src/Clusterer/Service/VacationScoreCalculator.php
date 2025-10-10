<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Contract\VacationScoreCalculatorInterface;
use MagicSunday\Memories\Clusterer\Selection\MemberSelectorInterface;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use MagicSunday\Memories\Clusterer\Support\VacationTimezoneTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Scoring\HolidayResolverInterface;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function abs;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_map;
use function array_sum;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function log;
use function max;
use function min;
use function ksort;
use function preg_replace;
use function round;
use function sort;
use function str_replace;
use function trim;
use function mb_strtoupper;
use function mb_substr;
use function spl_object_id;

use const SORT_NUMERIC;
use const SORT_STRING;

/**
 * Calculates vacation cluster drafts and scores.
 */
final class VacationScoreCalculator implements VacationScoreCalculatorInterface
{
    use VacationTimezoneTrait;

    private const float WEEKEND_OR_HOLIDAY_BONUS = 0.35;

    /**
     * @param float $movementThresholdKm minimum travel distance to count as move day
     * @param int   $minAwayDays         minimum number of away days required to accept a vacation
     * @param int   $minMembers          minimum number of media required to accept a vacation
     */
    public function __construct(
        private LocationHelper $locationHelper,
        private MemberSelectorInterface $memberSelector,
        private VacationSelectionOptions $selectionOptions,
        private HolidayResolverInterface $holidayResolver = new NullHolidayResolver(),
        private string $timezone = 'Europe/Berlin',
        private float $movementThresholdKm = 35.0,
        private int $minAwayDays = 1,
        private int $minMembers = 0,
        private ?JobMonitoringEmitterInterface $monitoringEmitter = null,
    ) {
        if ($this->timezone === '') {
            throw new InvalidArgumentException('timezone must not be empty.');
        }

        if ($this->movementThresholdKm <= 0.0) {
            throw new InvalidArgumentException('movementThresholdKm must be > 0.');
        }

        if ($this->minAwayDays < 1) {
            throw new InvalidArgumentException('minAwayDays must be >= 1.');
        }

        if ($this->minMembers < 0) {
            throw new InvalidArgumentException('minMembers must be >= 0.');
        }

    }

    /**
     * @param array<string, array{members:list<Media>,gpsMembers:list<Media>}> $days
     * @param array{lat:float,lon:float,start:int,end:int,dwell:int}            $staypoint
     *
     * @return list<Media>
     */
    private function collectStaypointMembers(array $days, array $staypoint): array
    {
        $members = [];
        $seen    = [];

        foreach ($days as $summary) {
            $candidates = $this->filterMembersWithinStaypoint($summary['members'], $staypoint['start'], $staypoint['end']);

            if ($summary['gpsMembers'] !== []) {
                $gpsCandidates = $this->filterMembersWithinStaypoint($summary['gpsMembers'], $staypoint['start'], $staypoint['end']);
                if ($gpsCandidates !== []) {
                    $candidates = array_merge($candidates, $gpsCandidates);
                }
            }

            foreach ($candidates as $media) {
                $objectId = spl_object_id($media);
                if (isset($seen[$objectId])) {
                    continue;
                }

                $members[]       = $media;
                $seen[$objectId] = true;
            }
        }

        return $members;
    }

    /**
     * @param list<Media> $members
     *
     * @return list<Media>
     */
    private function filterMembersWithinStaypoint(array $members, int $start, int $end): array
    {
        $filtered = [];

        foreach ($members as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $timestamp = $takenAt->getTimestamp();
            if ($timestamp >= $start && $timestamp <= $end) {
                $filtered[] = $media;
            }
        }

        return $filtered;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDraft(array $dayKeys, array $days, array $home): ?ClusterDraft
    {
        if ($dayKeys === []) {
            return null;
        }

        $rawMembers              = [];
        /** @var array<int, string> $memberDayIndex */
        $memberDayIndex          = [];
        $gpsMembers              = [];
        $maxDistance             = 0.0;
        $avgDistanceSum          = 0.0;
        $tourismHits             = 0;
        $poiSamples              = 0;
        $moveDays                = 0;
        $photoDensitySum         = 0.0;
        $photoDensityDenominator = 0;
        $timezoneOffsets         = [];
        $countryCodes            = [];
        $workDayPenalty          = 0;
        $reliableDays            = 0;
        $spotClusterCount        = 0;
        $multiSpotDays           = 0;
        $spotDwellSeconds        = 0;
        $weekendHolidayDays      = 0;
        $awayDays                = 0;
        $maxSpeedKmh             = 0.0;
        $avgSpeedKmhSum          = 0.0;
        $avgSpeedKmhSamples      = 0;
        $highSpeedTransit        = false;
        $cohortRatioSum          = 0.0;
        $cohortRatioSamples      = 0;
        $cohortMemberAggregate   = [];
        $primaryStaypoint        = null;

        $weekendHolidayFlags = [];

        foreach ($dayKeys as $key) {
            $summary = $days[$key];
            foreach ($summary['members'] as $media) {
                $rawMembers[] = $media;
                $memberDayIndex[spl_object_id($media)] = $key;
            }

            foreach ($summary['gpsMembers'] as $gpsMedia) {
                $gpsMembers[] = $gpsMedia;
            }

            if ($summary['maxDistanceKm'] > $maxDistance) {
                $maxDistance = $summary['maxDistanceKm'];
            }

            if ($summary['maxSpeedKmh'] > $maxSpeedKmh) {
                $maxSpeedKmh = $summary['maxSpeedKmh'];
            }

            if ($summary['avgSpeedKmh'] > 0.0) {
                $avgSpeedKmhSum += $summary['avgSpeedKmh'];
                ++$avgSpeedKmhSamples;
            }

            if ($summary['hasHighSpeedTransit']) {
                $highSpeedTransit = true;
            }

            $avgDistanceSum += $summary['avgDistanceKm'];
            if ($summary['baseAway']) {
                $cohortRatioSum += (float) ($summary['cohortPresenceRatio'] ?? 0.0);
                ++$cohortRatioSamples;

                $cohortMembers = $summary['cohortMembers'] ?? [];
                foreach ($cohortMembers as $personId => $count) {
                    if (!isset($cohortMemberAggregate[$personId])) {
                        $cohortMemberAggregate[$personId] = 0;
                    }

                    $cohortMemberAggregate[$personId] += (int) $count;
                }

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
                foreach ($summary['staypoints'] as $staypoint) {
                    if ($primaryStaypoint === null || $staypoint['dwell'] > $primaryStaypoint['dwell']) {
                        $primaryStaypoint = $staypoint;
                    }
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

            $isWeekendOrHoliday         = $this->isWeekendOrHoliday($summary, $home);
            $weekendHolidayFlags[$key] = $isWeekendOrHoliday;

            if ($summary['baseAway'] && $isWeekendOrHoliday) {
                ++$weekendHolidayDays;
            }
        }

        if ($awayDays < $this->minAwayDays || $reliableDays === 0) {
            return null;
        }

        if (count($rawMembers) < $this->minMembers) {
            return null;
        }

        $weekendHolidayDays += $this->countAdjacentWeekendHolidayDays($dayKeys, $days, $home, $weekendHolidayFlags);

        $avgCohortRatio = $cohortRatioSamples > 0 ? $cohortRatioSum / $cohortRatioSamples : 0.0;
        $avgCohortRatio = max(0.0, min(1.0, $avgCohortRatio));

        if ($cohortMemberAggregate !== []) {
            ksort($cohortMemberAggregate, SORT_NUMERIC);
        }

        $cohortBonus = min(1.5, $avgCohortRatio * 2.5);

        if ($gpsMembers === []) {
            return null;
        }

        $dayCount    = count($dayKeys);
        $avgDistance = $avgDistanceSum / $dayCount;

        $centroid           = MediaMath::centroid($gpsMembers);
        $centroidDistanceKm = MediaMath::haversineDistanceInMeters(
            $home['lat'],
            $home['lon'],
            $centroid['lat'],
            $centroid['lon'],
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

        $primaryStaypointCity          = null;
        $primaryStaypointRegion        = null;
        $primaryStaypointCountry       = null;
        $primaryStaypointLocation      = null;
        $primaryStaypointLocationParts = [];
        $primaryStaypointData          = null;

        if ($primaryStaypoint !== null) {
            $primaryStaypointData = [
                'lat'           => (float) $primaryStaypoint['lat'],
                'lon'           => (float) $primaryStaypoint['lon'],
                'start'         => (int) $primaryStaypoint['start'],
                'end'           => (int) $primaryStaypoint['end'],
                'dwell_seconds' => (int) $primaryStaypoint['dwell'],
            ];

            $staypointMembers    = $this->collectStaypointMembers($days, $primaryStaypoint);
            $staypointComponents = [];

            if ($staypointMembers !== []) {
                $staypointComponents = $this->locationHelper->majorityLocationComponents($staypointMembers);
            }

            if ($staypointComponents !== []) {
                $staypointCity = $staypointComponents['city'] ?? null;
                if ($staypointCity !== null) {
                    $cityLabel = $this->formatLocationComponent($staypointCity);
                    if ($cityLabel !== '') {
                        $primaryStaypointCity          = $cityLabel;
                        $primaryStaypointLocationParts[] = $cityLabel;
                    }
                }

                $staypointRegion = $staypointComponents['region'] ?? null;
                if ($staypointRegion !== null) {
                    $regionLabel = $this->formatLocationComponent($staypointRegion);
                    if ($regionLabel !== '') {
                        $primaryStaypointRegion = $regionLabel;
                        if (!in_array($regionLabel, $primaryStaypointLocationParts, true)) {
                            $primaryStaypointLocationParts[] = $regionLabel;
                        }
                    }
                }

                $staypointCountry = $staypointComponents['country'] ?? null;
                if ($staypointCountry !== null) {
                    $countryLabel = $this->formatLocationComponent($staypointCountry);
                    if ($countryLabel !== '') {
                        $primaryStaypointCountry = $countryLabel;
                        if (!in_array($countryLabel, $primaryStaypointLocationParts, true)) {
                            $primaryStaypointLocationParts[] = $countryLabel;
                        }
                    }
                }
            }

            if ($primaryStaypointLocationParts !== []) {
                $primaryStaypointLocation = implode(', ', $primaryStaypointLocationParts);
            } elseif (isset($staypointMembers) && $staypointMembers !== []) {
                $staypointLabel = $this->locationHelper->majorityLabel($staypointMembers);
                if ($staypointLabel !== null && $staypointLabel !== '') {
                    $primaryStaypointLocation = $staypointLabel;
                }
            }
        }

        $tourismRatio  = $poiSamples > 0 ? min(1.0, $tourismHits / max(1, $poiSamples)) : 0.0;
        $photoDensityZ = $photoDensityDenominator > 0 ? $photoDensitySum / $photoDensityDenominator : 0.0;
        $avgSpeedKmh   = $avgSpeedKmhSamples > 0 ? $avgSpeedKmhSum / $avgSpeedKmhSamples : 0.0;

        $firstDay    = $days[$dayKeys[0]];
        $lastDay     = $days[$dayKeys[$dayCount - 1]];
        $airportFlag = $firstDay['hasAirportPoi'] || $lastDay['hasAirportPoi'];

        $countryChange  = $countries !== [] && (count($countries) > 1 || ($home['country'] !== null && !in_array($home['country'], $countries, true)));
        $timezoneChange = $timezones !== [] && (count($timezones) > 1 || ($home['timezone_offset'] !== null && !in_array($home['timezone_offset'], $timezones, true)));

        $spotDwellHours      = $spotDwellSeconds / 3600.0;
        $multiSpotBonus      = min(3.0, $multiSpotDays * 0.9);
        $dwellBonus          = min(1.5, $spotDwellHours * 0.3);
        $spotBonus           = $multiSpotBonus + $dwellBonus;
        $weekendHolidayBonus = min(2.0, $weekendHolidayDays * self::WEEKEND_OR_HOLIDAY_BONUS);

        $awayDayScore        = min(10, $awayDays) * 1.6;
        $distanceScore       = $centroidDistanceKm > 0.0 ? 1.2 * log(1.0 + $centroidDistanceKm) : 0.0;
        $countryBonus        = $countryChange ? 2.5 : 0.0;
        $timezoneBonus       = $timezoneChange ? 2.0 : 0.0;
        $tourismBonus        = 1.5 * $tourismRatio;
        $moveBonus           = 0.8 * $moveDays;
        $airportBonus        = $airportFlag ? 1.0 : 0.0;
        $densityBonus        = 0.6 * $photoDensityZ;
        $explorationBonus    = $spotBonus;
        $weekendHolidayScore = $weekendHolidayBonus;
        $penalty             = 0.4 * $workDayPenalty;

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
            + $cohortBonus
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

        $acceptedSummaries = array_intersect_key($days, array_flip($dayKeys));
        $preSelectionCount = count($rawMembers);

        if ($this->monitoringEmitter !== null) {
            $startPayload = [
                'pre_count'                 => $preSelectionCount,
                'day_count'                 => $dayCount,
                'away_days'                 => $awayDays,
                'staypoint_detected'        => $primaryStaypoint !== null,
            ];

            if ($primaryStaypoint !== null) {
                $startPayload['primary_staypoint_dwell_s'] = (int) $primaryStaypoint['dwell'];
            }

            $this->monitoringEmitter->emit('vacation_curation', 'selection_start', $startPayload);
        }

        $selectionResult    = $this->memberSelector->select($acceptedSummaries, $home, $this->selectionOptions);
        $curatedMembers     = $selectionResult->getMembers();
        $selectionTelemetry = $selectionResult->getTelemetry();
        $selectedCount      = count($curatedMembers);
        $droppedCount       = $preSelectionCount > $selectedCount ? $preSelectionCount - $selectedCount : 0;

        $spacingSamples   = [];
        $previousTimestamp = null;
        foreach ($curatedMembers as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $timestamp = $takenAt->getTimestamp();
            if ($previousTimestamp !== null) {
                $spacingSamples[] = abs($timestamp - $previousTimestamp);
            }

            $previousTimestamp = $timestamp;
        }

        $averageSpacingSeconds = $spacingSamples !== []
            ? array_sum($spacingSamples) / count($spacingSamples)
            : 0.0;

        $nearDupBlocked    = (int) ($selectionTelemetry['near_duplicate_blocked'] ?? 0);
        $nearDupReplaced   = (int) ($selectionTelemetry['near_duplicate_replacements'] ?? 0);
        $spacingRejections = (int) ($selectionTelemetry['spacing_rejections'] ?? 0);

        if ($this->monitoringEmitter !== null) {
            $this->monitoringEmitter->emit(
                'vacation_curation',
                'selection_completed',
                [
                    'pre_count'                => $preSelectionCount,
                    'post_count'               => $selectedCount,
                    'dropped_total'            => $droppedCount,
                    'near_duplicates_removed'  => $nearDupBlocked,
                    'near_duplicates_replaced' => $nearDupReplaced,
                    'spacing_rejections'       => $spacingRejections,
                    'average_spacing_seconds'  => $averageSpacingSeconds,
                ]
            );
        }

        if ($curatedMembers === []) {
            return null;
        }

        $perDayCounts = [];
        foreach ($curatedMembers as $media) {
            $objectId = spl_object_id($media);
            $dayKey   = $memberDayIndex[$objectId] ?? null;

            if ($dayKey === null) {
                continue;
            }

            if (!isset($perDayCounts[$dayKey])) {
                $perDayCounts[$dayKey] = 0;
            }

            ++$perDayCounts[$dayKey];
        }

        $orderedDistribution = [];
        foreach ($dayKeys as $dayKey) {
            if (isset($perDayCounts[$dayKey])) {
                $orderedDistribution[$dayKey] = $perDayCounts[$dayKey];
            }
        }

        $timeRange = MediaMath::timeRange($curatedMembers);

        $memberIds = array_map(
            static fn (Media $media): int => $media->getId(),
            $curatedMembers
        );

        // Raw member aggregates continue to inform scoring metrics, while
        // curated members drive presentation metadata below.
        $place           = $this->locationHelper->majorityLabel($curatedMembers);
        $placeComponents = $this->locationHelper->majorityLocationComponents($curatedMembers);

        $classificationLabels = [
            'vacation'   => 'Urlaub',
            'short_trip' => 'Kurztrip',
            'day_trip'   => 'Tagesausflug',
        ];

        $params = [
            'classification'           => $classification,
            'classification_label'     => $classificationLabels[$classification] ?? 'Reise',
            'score'                    => round($score, 2),
            'nights'                   => max(0, $awayDays - 1),
            'away_days'                => $awayDays,
            'total_days'               => $dayCount,
            'time_range'               => $timeRange,
            'max_distance_km'          => $centroidDistanceKm,
            'max_observed_distance_km' => $maxDistance,
            'avg_distance_km'          => $avgDistance,
            'country_change'           => $countryChange,
            'timezone_change'          => $timezoneChange,
            'tourism_ratio'            => $tourismRatio,
            'move_days'                => $moveDays,
            'photo_density_z'          => $photoDensityZ,
            'airport_transfer'         => $airportFlag,
            'max_speed_kmh'            => $maxSpeedKmh,
            'avg_speed_kmh'            => $avgSpeedKmh,
            'high_speed_transit'       => $highSpeedTransit,
            'spot_count'               => $spotClusterCount,
            'spot_cluster_days'        => $multiSpotDays,
            'spot_dwell_hours'         => round($spotDwellHours, 2),
            'spot_exploration_bonus'   => round($explorationBonus, 2),
            'weekend_holiday_days'     => $weekendHolidayDays,
            'weekend_holiday_bonus'    => round($weekendHolidayBonus, 2),
            'cohort_bonus'              => round($cohortBonus, 2),
            'cohort_presence_ratio'     => round($avgCohortRatio, 3),
            'cohort_members'            => $cohortMemberAggregate,
            'work_day_penalty_days'    => $workDayPenalty,
            'work_day_penalty_score'   => round($penalty, 2),
            'timezones'                => $timezones,
            'countries'                => $countries,
        ];

        $params['member_selection'] = [
            'counts' => [
                'pre'     => $preSelectionCount,
                'post'    => $selectedCount,
                'dropped' => $droppedCount,
            ],
            'near_duplicates' => [
                'blocked'      => $nearDupBlocked,
                'replacements' => $nearDupReplaced,
            ],
            'spacing' => [
                'average_seconds' => $averageSpacingSeconds,
                'rejections'      => $spacingRejections,
            ],
            'per_day_distribution' => $orderedDistribution,
            'options' => [
                'selector'            => $this->memberSelector::class,
                'target_total'        => $this->selectionOptions->targetTotal,
                'max_per_day'         => $this->selectionOptions->maxPerDay,
                'time_slot_hours'     => $this->selectionOptions->timeSlotHours,
                'min_spacing_seconds' => $this->selectionOptions->minSpacingSeconds,
                'phash_min_hamming'   => $this->selectionOptions->phashMinHamming,
                'max_per_staypoint'   => $this->selectionOptions->maxPerStaypoint,
                'video_bonus'         => $this->selectionOptions->videoBonus,
                'face_bonus'          => $this->selectionOptions->faceBonus,
                'selfie_penalty'      => $this->selectionOptions->selfiePenalty,
                'quality_floor'       => $this->selectionOptions->qualityFloor,
            ],
            'telemetry' => $selectionTelemetry,
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
                    $locationParts[]      = $cityLabel;
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

        if ($primaryStaypointData !== null) {
            $params['primaryStaypoint'] = $primaryStaypointData;
        }

        if ($primaryStaypointCity !== null) {
            $params['primaryStaypointCity'] = $primaryStaypointCity;
        }

        if ($primaryStaypointRegion !== null) {
            $params['primaryStaypointRegion'] = $primaryStaypointRegion;
        }

        if ($primaryStaypointCountry !== null) {
            $params['primaryStaypointCountry'] = $primaryStaypointCountry;
        }

        if ($primaryStaypointLocation !== null) {
            $params['primaryStaypointLocation'] = $primaryStaypointLocation;
        }

        if ($primaryStaypointLocationParts !== []) {
            $params['primaryStaypointLocationParts'] = $primaryStaypointLocationParts;
        }

        $placeCityMissing = !isset($params['place_city']) || $params['place_city'] === '';
        if ($placeCityMissing && $primaryStaypointCity !== null) {
            $params['place_city'] = $primaryStaypointCity;
        }

        $placeRegionMissing = !isset($params['place_region']) || $params['place_region'] === '';
        if ($placeRegionMissing && $primaryStaypointRegion !== null) {
            $params['place_region'] = $primaryStaypointRegion;
        }

        $placeCountryMissing = !isset($params['place_country']) || $params['place_country'] === '';
        if ($placeCountryMissing && $primaryStaypointCountry !== null) {
            $params['place_country'] = $primaryStaypointCountry;
        }

        $placeLocationMissing = !isset($params['place_location']) || $params['place_location'] === '';

        $resolvedLocationParts = [];
        $resolvedCity           = $params['place_city'] ?? null;
        if (is_string($resolvedCity) && $resolvedCity !== '') {
            $resolvedLocationParts[] = $resolvedCity;
        }

        $resolvedRegion = $params['place_region'] ?? null;
        if (is_string($resolvedRegion) && $resolvedRegion !== '' && !in_array($resolvedRegion, $resolvedLocationParts, true)) {
            $resolvedLocationParts[] = $resolvedRegion;
        }

        $resolvedCountry = $params['place_country'] ?? null;
        if (is_string($resolvedCountry) && $resolvedCountry !== '' && !in_array($resolvedCountry, $resolvedLocationParts, true)) {
            $resolvedLocationParts[] = $resolvedCountry;
        }

        if ($resolvedLocationParts !== []) {
            $resolvedLocation = implode(', ', $resolvedLocationParts);
            $currentLocation  = $params['place_location'] ?? null;
            if (!is_string($currentLocation) || $currentLocation !== $resolvedLocation) {
                $params['place_location'] = $resolvedLocation;
            }
        } elseif ($placeLocationMissing && $primaryStaypointLocation !== null) {
            $params['place_location'] = $primaryStaypointLocation;
        }

        $draft = new ClusterDraft(
            algorithm: 'vacation',
            params: $params,
            centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
            members: $memberIds,
        );
        return $draft;
    }

    /**
     * @param list<string>                                               $dayKeys
     * @param array<string, array{date:string}>                          $days
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
     * @param array<string, bool>                                        $weekendHolidayFlags
     */
    private function countAdjacentWeekendHolidayDays(array $dayKeys, array $days, array $home, array $weekendHolidayFlags): int
    {
        if ($dayKeys === []) {
            return 0;
        }

        $extra      = 0;
        $firstKey   = $dayKeys[0];
        $lastKey    = $dayKeys[count($dayKeys) - 1];
        $neighbors  = [
            $this->neighborDayKey($firstKey, -1, $days),
            $this->neighborDayKey($lastKey, 1, $days),
        ];

        foreach ($neighbors as $neighborKey) {
            if ($neighborKey === null || in_array($neighborKey, $dayKeys, true)) {
                continue;
            }

            $isWeekendOrHoliday = $weekendHolidayFlags[$neighborKey] ?? $this->isWeekendOrHoliday($days[$neighborKey], $home);
            if ($isWeekendOrHoliday) {
                ++$extra;
            }
        }

        return $extra;
    }

    /**
     * @param array<string, array{date:string}> $days
     */
    private function neighborDayKey(string $referenceKey, int $offset, array $days): ?string
    {
        if ($offset === 0) {
            return null;
        }

        $timezone = new DateTimeZone('UTC');
        $date     = DateTimeImmutable::createFromFormat('!Y-m-d', $referenceKey, $timezone);
        if ($date === false) {
            return null;
        }

        $modifier = $offset > 0 ? '+' . $offset . ' day' : $offset . ' day';
        $candidate = $date->modify($modifier);
        if ($candidate === false) {
            return null;
        }

        $key = $candidate->format('Y-m-d');

        return array_key_exists($key, $days) ? $key : null;
    }

    /**
     * @param array{weekday:int,date:string} $summary
     * @param array{lat:float,lon:float,radius_km:float,country:string|null,timezone_offset:int|null} $home
     */
    private function isWeekendOrHoliday(array $summary, array $home): bool
    {
        $dayTimezone = $this->resolveSummaryTimezone($summary, $home);
        $dayDate     = new DateTimeImmutable($summary['date'], $dayTimezone);
        $isWeekend   = $summary['weekday'] >= 6;
        $isHoliday   = $this->holidayResolver->isHoliday($dayDate);

        return $isWeekend || $isHoliday;
    }

    /**
     * Normalizes a location label by replacing separators and capitalising its parts.
     */
    private function formatLocationComponent(string $value): string
    {
        $value = str_replace('_', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        $parts = explode('-', $value);
        foreach ($parts as $index => $part) {
            $normalizedPart = trim($part);
            if ($normalizedPart === '') {
                $parts[$index] = $normalizedPart;

                continue;
            }

            $words = explode(' ', $normalizedPart);
            foreach ($words as $wordIndex => $word) {
                if ($word === '') {
                    continue;
                }

                $firstCharacter = mb_substr($word, 0, 1);
                if ($firstCharacter === '') {
                    continue;
                }

                $remainder = mb_substr($word, 1);
                $words[$wordIndex] = mb_strtoupper($firstCharacter) . ($remainder === false ? '' : $remainder);
            }

            $parts[$index] = implode(' ', $words);
        }

        return implode('-', $parts);
    }
}
