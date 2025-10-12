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
use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use MagicSunday\Memories\Clusterer\Support\VacationTimezoneTrait;
use MagicSunday\Memories\Clusterer\Support\StaypointIndex;
use MagicSunday\Memories\Entity\Location;
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
use function is_array;
use function is_string;
use function exp;
use function max;
use function min;
use function ksort;
use function preg_replace;
use function round;
use function sort;
use function str_replace;
use function trim;
use function mb_strtolower;
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

    private SelectionProfileProvider $selectionProfiles;

    private string $selectionProfileKey;

    private DateTimeImmutable $referenceNow;

    /**
     * @param float $movementThresholdKm minimum travel distance to count as move day
     * @param int   $minAwayDays         minimum number of away days required to accept a vacation
     * @param int   $minMembers          minimum number of media required to accept a vacation
     */
    public function __construct(
        private LocationHelper $locationHelper,
        private MemberSelectorInterface $memberSelector,
        SelectionProfileProvider $selectionProfiles,
        private HolidayResolverInterface $holidayResolver = new NullHolidayResolver(),
        private string $timezone = 'Europe/Berlin',
        private float $movementThresholdKm = 35.0,
        private int $minAwayDays = 1,
        private int $minMembers = 0,
        private ?JobMonitoringEmitterInterface $monitoringEmitter = null,
        ?DateTimeImmutable $referenceDate = null,
    ) {
        $this->selectionProfiles   = $selectionProfiles;
        $this->selectionProfileKey = $this->selectionProfiles->determineProfileKey('vacation');

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

        $timezone = new DateTimeZone($this->timezone);
        $this->referenceNow = $referenceDate !== null
            ? $referenceDate->setTimezone($timezone)
            : new DateTimeImmutable('now', $timezone);
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
        $poiTypeSamples          = [];
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
        $transitDays             = 0;
        $cohortRatioSum          = 0.0;
        $cohortRatioSamples      = 0;
        $cohortMemberAggregate   = [];
        $primaryStaypoint        = null;
        $baseAwayMap             = [];
        $photoCountMap           = [];
        $syntheticMap            = [];

        $weekendHolidayFlags = [];

        foreach ($dayKeys as $key) {
            $summary             = $days[$key];
            $baseAwayMap[$key]   = (bool) $summary['baseAway'];
            $photoCountMap[$key] = (int) $summary['photoCount'];
            $syntheticMap[$key]  = (bool) ($summary['isSynthetic'] ?? false);

            foreach ($summary['members'] as $media) {
                $rawMembers[] = $media;
                $memberDayIndex[spl_object_id($media)] = $key;
                $location = $media->getLocation();
                if ($location instanceof Location) {
                    $poiType = $this->resolvePoiType($location->getType(), $location->getCategory());
                    if ($poiType !== null) {
                        $poiTypeSamples[$poiType] = true;
                    }
                }
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
                ++$transitDays;
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

        if ($reliableDays === 0) {
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

        $dayCount = count($dayKeys);

        $bridgedAwayDays   = $this->countBridgedAwayDays($dayKeys, $baseAwayMap, $photoCountMap, $syntheticMap);
        $effectiveAwayDays = $awayDays + $bridgedAwayDays;

        if ($effectiveAwayDays < $this->minAwayDays) {
            return null;
        }

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
        $explorationBonus    = $spotBonus;
        $weekendHolidayBonus = min(2.0, $weekendHolidayDays * self::WEEKEND_OR_HOLIDAY_BONUS);
        $workDayPenaltyScore = 0.4 * $workDayPenalty;

        $poiDiversity = $poiTypeSamples !== [] ? min(1.0, count($poiTypeSamples) / 6.0) : 0.0;
        $peopleShare  = $this->computePeopleShare($rawMembers);

        $qualityComponents = [
            $this->clamp01($reliableDays / max(1, $dayCount)),
            $this->sigmoid($photoDensityZ),
            $this->clamp01($multiSpotDays > 0 ? $multiSpotDays / max(1, $effectiveAwayDays) : 0.0),
            $this->clamp01($weekendHolidayDays / max(1, $effectiveAwayDays)),
            $this->clamp01($avgCohortRatio),
            $this->clamp01($cohortBonus / 1.5),
        ];
        $quality = $this->average($qualityComponents);

        $awayDaysNorm    = $this->clamp01($effectiveAwayDays / 5.0);
        $maxDistanceValue = max($centroidDistanceKm, $maxDistance);
        $maxDistanceNorm = $this->normalizeDistance($maxDistanceValue);
        $recencyScore    = $this->computeRecencyScore($rawMembers);

        $scoreComponents = [
            'quality'           => $quality,
            'tourism_ratio'     => $tourismRatio,
            'away_days_norm'    => $awayDaysNorm,
            'max_distance_norm' => $maxDistanceNorm,
            'people'            => $peopleShare,
            'poi_diversity'     => $poiDiversity,
            'recency'           => $recencyScore,
        ];

        $weightedScore = (0.28 * $scoreComponents['quality'])
            + (0.18 * $scoreComponents['tourism_ratio'])
            + (0.16 * $scoreComponents['away_days_norm'])
            + (0.14 * $scoreComponents['max_distance_norm'])
            + (0.10 * $scoreComponents['people'])
            + (0.08 * $scoreComponents['poi_diversity'])
            + (0.06 * $scoreComponents['recency']);

        $transitRatio   = $dayCount > 0 ? $transitDays / $dayCount : 0.0;
        $transitPenalty = 0.0;
        if ($transitRatio > 0.3) {
            $transitPenalty = $this->clamp01(($transitRatio - 0.3) / 0.7);
        }

        $weightedScore -= $transitPenalty;
        $weightedScore  = $this->clamp01($weightedScore);
        $score          = $weightedScore * 10.0;

        $nights = max(0, $effectiveAwayDays - 1);

        $classification = $this->classifyTrip($effectiveAwayDays, $awayDays, $nights, $maxDistanceValue);
        if ($classification === 'none') {
            return null;
        }

        $classification = $this->applyScoreThresholds($classification, $score);
        if ($classification === null) {
            return null;
        }

        $scoreComponentOutput = [
            'quality'           => round($scoreComponents['quality'], 3),
            'tourism_ratio'     => round($scoreComponents['tourism_ratio'], 3),
            'away_days_norm'    => round($scoreComponents['away_days_norm'], 3),
            'max_distance_norm' => round($scoreComponents['max_distance_norm'], 3),
            'people'            => round($scoreComponents['people'], 3),
            'poi_diversity'     => round($scoreComponents['poi_diversity'], 3),
            'recency'           => round($scoreComponents['recency'], 3),
        ];

        $acceptedSummaries = array_intersect_key($days, array_flip($dayKeys));
        $preSelectionCount = count($rawMembers);
        $storyline         = $this->resolveStoryline(
            $classification,
            $moveDays,
            $transitRatio,
            $multiSpotDays,
        );

        if ($this->monitoringEmitter !== null) {
            $startPayload = [
                'pre_count'                 => $preSelectionCount,
                'day_count'                 => $dayCount,
                'away_days'                 => $effectiveAwayDays,
                'raw_away_days'             => $awayDays,
                'bridged_days'              => $bridgedAwayDays,
                'staypoint_detected'        => $primaryStaypoint !== null,
                'storyline'                 => $storyline,
            ];

            if ($primaryStaypoint !== null) {
                $startPayload['primary_staypoint_dwell_s'] = (int) $primaryStaypoint['dwell'];
            }

            $this->monitoringEmitter->emit('vacation_curation', 'selection_start', $startPayload);
        }

        $selectionOptions   = $this->selectionProfiles->createOptions($this->selectionProfileKey);
        $selectionResult    = $this->memberSelector->select($acceptedSummaries, $home, $selectionOptions);
        $curatedMembers     = $selectionResult->getMembers();
        $selectionTelemetry = $selectionResult->getTelemetry();
        $selectedCount      = count($curatedMembers);
        $droppedCount       = $preSelectionCount > $selectedCount ? $preSelectionCount - $selectedCount : 0;

        if (!isset($selectionTelemetry['averages']) || !is_array($selectionTelemetry['averages'])) {
            $selectionTelemetry['averages'] = [];
        }

        if (!isset($selectionTelemetry['relaxation_hints']) || !is_array($selectionTelemetry['relaxation_hints'])) {
            $selectionTelemetry['relaxation_hints'] = [];
        }

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
                    'storyline'                => $storyline,
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
            'score_components'         => $scoreComponentOutput,
            'nights'                   => $nights,
            'away_days'                => $effectiveAwayDays,
            'raw_away_days'            => $awayDays,
            'bridged_away_days'        => $bridgedAwayDays,
            'away_days_norm'           => round($awayDaysNorm, 3),
            'total_days'               => $dayCount,
            'time_range'               => $timeRange,
            'max_distance_km'          => $centroidDistanceKm,
            'max_observed_distance_km' => $maxDistance,
            'avg_distance_km'          => $avgDistance,
            'country_change'           => $countryChange,
            'timezone_change'          => $timezoneChange,
            'tourism_ratio'            => $tourismRatio,
            'poi_diversity'            => round($poiDiversity, 3),
            'move_days'                => $moveDays,
            'photo_density_z'          => $photoDensityZ,
            'airport_transfer'         => $airportFlag,
            'max_speed_kmh'            => $maxSpeedKmh,
            'avg_speed_kmh'            => $avgSpeedKmh,
            'high_speed_transit'       => $highSpeedTransit,
            'transit_days'             => $transitDays,
            'transit_ratio'            => round($transitRatio, 3),
            'transit_penalty'          => round($transitPenalty, 3),
            'transit_penalty_score'    => round($transitPenalty * 10.0, 2),
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
            'work_day_penalty_score'   => round($workDayPenaltyScore, 2),
            'people_ratio'             => round($peopleShare, 3),
            'max_distance_norm'        => round($maxDistanceNorm, 3),
            'recency_score'            => round($recencyScore, 3),
            'timezones'                => $timezones,
            'countries'                => $countries,
        ];

        $params['storyline'] = $storyline;

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
            'per_bucket_distribution' => $selectionTelemetry['per_bucket_distribution'] ?? [],
            'options' => [
                'selector'            => $this->memberSelector::class,
                'target_total'        => $selectionOptions->targetTotal,
                'max_per_day'         => $selectionOptions->maxPerDay,
                'time_slot_hours'     => $selectionOptions->timeSlotHours,
                'min_spacing_seconds' => $selectionOptions->minSpacingSeconds,
                'phash_min_hamming'   => $selectionOptions->phashMinHamming,
                'max_per_staypoint'   => $selectionOptions->maxPerStaypoint,
                'video_bonus'         => $selectionOptions->videoBonus,
                'face_bonus'          => $selectionOptions->faceBonus,
                'selfie_penalty'      => $selectionOptions->selfiePenalty,
                'quality_floor'       => $selectionOptions->qualityFloor,
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

        $staypointMetadata = $this->buildStaypointMetadata($dayKeys, $days, $curatedMembers);
        if ($staypointMetadata['keys'] !== [] || $staypointMetadata['counts'] !== []) {
            $params['staypoints'] = $staypointMetadata;
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
            storyline: $storyline,
        );
        return $draft;
    }

    /**
     * @param list<string> $dayKeys
     * @param array<string, array{staypointIndex?:StaypointIndex,staypointCounts?:array<string,int>,members:list<Media>,gpsMembers:list<Media>}> $days
     * @param list<Media>  $members
     *
     * @return array{keys: array<int,string>, counts: array<string,int>}
     */
    private function buildStaypointMetadata(array $dayKeys, array $days, array $members): array
    {
        $indexMap = [];
        $counts   = [];

        foreach ($dayKeys as $dayKey) {
            $summary = $days[$dayKey] ?? null;
            if (!is_array($summary)) {
                continue;
            }

            $index = $summary['staypointIndex'] ?? null;
            if ($index instanceof StaypointIndex) {
                foreach ($index->getCounts() as $key => $count) {
                    $counts[$key] = ($counts[$key] ?? 0) + (int) $count;
                }

                foreach ($summary['members'] as $media) {
                    if (!$media instanceof Media) {
                        continue;
                    }

                    $id = $media->getId();
                    if ($id === null) {
                        continue;
                    }

                    $key = $index->get($media);
                    if ($key === null) {
                        continue;
                    }

                    $indexMap[(int) $id] = $key;
                }

                foreach ($summary['gpsMembers'] as $media) {
                    if (!$media instanceof Media) {
                        continue;
                    }

                    $id = $media->getId();
                    if ($id === null) {
                        continue;
                    }

                    $key = $index->get($media);
                    if ($key === null) {
                        continue;
                    }

                    $indexMap[(int) $id] = $key;
                }
            }

            $staypointCounts = $summary['staypointCounts'] ?? [];
            if (is_array($staypointCounts)) {
                foreach ($staypointCounts as $key => $count) {
                    if (!is_string($key)) {
                        continue;
                    }

                    $counts[$key] = ($counts[$key] ?? 0) + (int) $count;
                }
            }
        }

        $filtered = [];
        foreach ($members as $media) {
            if (!$media instanceof Media) {
                continue;
            }

            $id = $media->getId();
            if ($id === null) {
                continue;
            }

            $intId = (int) $id;
            if (isset($indexMap[$intId])) {
                $filtered[$intId] = $indexMap[$intId];
            }
        }

        return [
            'keys'   => $filtered,
            'counts' => $counts,
        ];
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
     * Normalizes a location component by replacing separators and capitalizing each word.
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

    /**
     * @param list<string>     $dayKeys
     * @param array<string,bool> $baseAwayMap
     * @param array<string,int> $photoCountMap
     * @param array<string,bool> $syntheticMap
     */
    private function countBridgedAwayDays(array $dayKeys, array $baseAwayMap, array $photoCountMap, array $syntheticMap): int
    {
        $bridged = 0;
        $total   = count($dayKeys);

        for ($index = 0; $index < $total; ++$index) {
            $key       = $dayKeys[$index];
            $isAway    = $baseAwayMap[$key] ?? false;
            $photoCount = $photoCountMap[$key] ?? 0;
            $isSynthetic = $syntheticMap[$key] ?? false;

            if ($isAway || $isSynthetic || $photoCount > 2) {
                continue;
            }

            $previousAway = false;
            if ($index > 0) {
                $previousKey = $dayKeys[$index - 1];
                $previousAway = $baseAwayMap[$previousKey] ?? false;
            }

            $nextAway = false;
            if ($index + 1 < $total) {
                $nextKey = $dayKeys[$index + 1];
                $nextAway = $baseAwayMap[$nextKey] ?? false;
            }

            if ($previousAway && $nextAway) {
                ++$bridged;
            }
        }

        return $bridged;
    }

    private function resolvePoiType(?string $type, ?string $category): ?string
    {
        $candidate = $type;
        if ($candidate === null || trim($candidate) === '') {
            $candidate = $category;
        }

        if ($candidate === null) {
            return null;
        }

        $normalized = trim(mb_strtolower($candidate));
        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * @param list<Media> $members
     */
    private function computePeopleShare(array $members): float
    {
        $faceMedia   = 0;
        $nonSelfie   = 0;

        foreach ($members as $media) {
            if ($media->hasFaces() !== true) {
                continue;
            }

            ++$faceMedia;

            if (!$this->isLikelySelfie($media)) {
                ++$nonSelfie;
            }
        }

        if ($faceMedia === 0) {
            return 0.0;
        }

        return $nonSelfie / $faceMedia;
    }

    private function isLikelySelfie(Media $media): bool
    {
        $persons = $media->getPersons();
        if (!is_array($persons)) {
            return false;
        }

        if (count($persons) !== 1) {
            return false;
        }

        return $media->hasFaces() === true;
    }

    /**
     * @param list<Media> $members
     */
    private function computeRecencyScore(array $members): float
    {
        $latest = null;

        foreach ($members as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $timestamp = $takenAt->getTimestamp();
            if ($latest === null || $timestamp > $latest) {
                $latest = $timestamp;
            }
        }

        if ($latest === null) {
            return 0.0;
        }

        $ageSeconds = $this->referenceNow->getTimestamp() - $latest;
        if ($ageSeconds <= 0) {
            return 1.0;
        }

        $ageDays = $ageSeconds / 86400.0;
        if ($ageDays >= 730.0) {
            return 0.0;
        }

        return 1.0 - ($ageDays / 730.0);
    }

    private function normalizeDistance(float $distanceKm): float
    {
        if ($distanceKm <= 0.0) {
            return 0.0;
        }

        $scaled = 1.0 - exp(-$distanceKm / 400.0);

        return $this->clamp01($scaled);
    }

    private function clamp01(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    private function sigmoid(float $value): float
    {
        return 1.0 / (1.0 + exp(-$value));
    }

    /**
     * @param list<float> $values
     */
    private function average(array $values): float
    {
        $sum   = 0.0;
        $count = 0;

        foreach ($values as $value) {
            $sum += $value;
            ++$count;
        }

        if ($count === 0) {
            return 0.0;
        }

        return $sum / $count;
    }

    private function classifyTrip(int $effectiveAwayDays, int $rawAwayDays, int $nights, float $distanceKm): string
    {
        if ($effectiveAwayDays <= 0) {
            return 'none';
        }

        if ($effectiveAwayDays <= 1 || $nights === 0) {
            return 'day_trip';
        }

        if ($rawAwayDays <= 2 && $effectiveAwayDays <= 3) {
            return 'short_trip';
        }

        if ($nights >= 4 || $effectiveAwayDays >= 5) {
            return 'vacation';
        }

        if ($distanceKm >= 1500.0 && $nights >= 2) {
            return 'vacation';
        }

        if ($nights <= 3) {
            return 'short_trip';
        }

        return 'vacation';
    }

    private function resolveStoryline(
        string $classification,
        int $moveDays,
        float $transitRatio,
        int $multiSpotDays,
    ): string {
        $prefix = 'vacation';

        if ($classification === 'day_trip') {
            return $prefix . '.day_trip';
        }

        if ($classification === 'short_trip') {
            return $prefix . '.short_trip';
        }

        if ($transitRatio >= 0.45 && $moveDays >= 2) {
            return $prefix . '.transit';
        }

        if ($multiSpotDays >= 3) {
            return $prefix . '.explorer';
        }

        return $prefix . '.extended';
    }

    private function applyScoreThresholds(string $classification, float $score): ?string
    {
        $thresholds = [
            'day_trip'   => 4.0,
            'short_trip' => 5.5,
            'vacation'   => 7.0,
        ];

        $order = ['vacation', 'short_trip', 'day_trip'];

        for ($index = 0; $index < count($order); ++$index) {
            if ($order[$index] !== $classification) {
                continue;
            }

            for ($candidateIndex = $index; $candidateIndex < count($order); ++$candidateIndex) {
                $candidate = $order[$candidateIndex];
                $threshold = $thresholds[$candidate];
                if ($score >= $threshold) {
                    return $candidate;
                }
            }

            return null;
        }

        return null;
    }
}
