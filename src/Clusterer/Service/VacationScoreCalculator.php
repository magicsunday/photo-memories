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
use Exception;
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
use MagicSunday\Memories\Service\Clusterer\Title\StoryTitleBuilder;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function abs;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_map;
use function array_sum;
use function ceil;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function exp;
use function floor;
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

    /**
     * @var list<string>
     */
    private const MISSING_CORE_CATEGORY_EXCEPTIONS = [];

    private SelectionProfileProvider $selectionProfiles;

    private string $defaultSelectionProfileKey;

    private DateTimeImmutable $referenceNow;

    /**
     * @var array{enabled:bool,min_nights:int,max_nights:int,min_flagged_days:int,require_saturday:bool,require_sunday:bool,require_weekend_flag:bool}
     */
    private array $weekendExceptionConfig;

    private ?string $weekendSelectionProfile;

    /**
     * @param float $movementThresholdKm minimum travel distance to count as move day
     * @param int   $minAwayDays         minimum number of away days required to accept a vacation
     * @param int   $minItemsPerDay      expected minimum number of items captured per away day
     * @param int   $minimumMemberFloor  base floor applied to the adaptive member threshold
     * @param int   $minMembers          minimum number of media required to accept a vacation
     */
    public function __construct(
        private LocationHelper $locationHelper,
        private MemberSelectorInterface $memberSelector,
        SelectionProfileProvider $selectionProfiles,
        private StoryTitleBuilder $storyTitleBuilder,
        private HolidayResolverInterface $holidayResolver = new NullHolidayResolver(),
        private string $timezone = 'Europe/Berlin',
        private float $movementThresholdKm = 35.0,
        private int $minAwayDays = 2,
        private int $minItemsPerDay = 4,
        private int $minimumMemberFloor = 60,
        private int $minMembers = 0,
        array $weekendExceptionConfig = [],
        ?string $weekendSelectionProfile = null,
        private ?JobMonitoringEmitterInterface $monitoringEmitter = null,
        ?DateTimeImmutable $referenceDate = null,
    ) {
        $this->selectionProfiles = $selectionProfiles;
        $this->defaultSelectionProfileKey = $this->selectionProfiles->determineProfileKey(
            'vacation',
            null,
            ['away_days' => 4],
        );
        if ($this->defaultSelectionProfileKey === 'vacation') {
            $this->defaultSelectionProfileKey = 'vacation_weekend_transit';
        }
        $this->weekendExceptionConfig     = $this->sanitizeWeekendExceptionConfig($weekendExceptionConfig);

        $profileOverride = $weekendSelectionProfile !== null ? trim($weekendSelectionProfile) : '';
        $this->weekendSelectionProfile = $profileOverride !== '' ? $profileOverride : null;

        if ($this->timezone === '') {
            throw new InvalidArgumentException('timezone must not be empty.');
        }

        if ($this->movementThresholdKm <= 0.0) {
            throw new InvalidArgumentException('movementThresholdKm must be > 0.');
        }

        if ($this->minAwayDays < 1) {
            throw new InvalidArgumentException('minAwayDays must be >= 1.');
        }

        if ($this->minItemsPerDay < 1) {
            throw new InvalidArgumentException('minItemsPerDay must be >= 1.');
        }

        if ($this->minimumMemberFloor < 0) {
            throw new InvalidArgumentException('minimumMemberFloor must be >= 0.');
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
    public function buildDraft(array $dayKeys, array $days, array $home, array $dayContext = []): ?ClusterDraft
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
        $poiPresence             = [];

        $weekendHolidayFlags = [];

        foreach ($dayKeys as $key) {
            $summary             = $days[$key];
            $baseAwayMap[$key]   = (bool) $summary['baseAway'];
            $photoCountMap[$key] = (int) $summary['photoCount'];
            $syntheticMap[$key]  = (bool) ($summary['isSynthetic'] ?? false);
            $poiPresence[$key]   = ($summary['tourismHits'] > 0) || ($summary['poiSamples'] > 0);

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

        $rawMemberCount = count($rawMembers);

        if ($reliableDays === 0) {
            return null;
        }

        $minimumMemberFloor = 0;

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
        $nights            = max(0, $effectiveAwayDays - 1);

        $minimumMemberFloor = $this->resolveMinimumMemberFloor(max(1, $awayDays));

        if ($rawMemberCount < $minimumMemberFloor) {
            if ($this->monitoringEmitter !== null) {
                $this->monitoringEmitter->emit(
                    'vacation_curation',
                    'insufficient_members',
                    [
                        'raw_member_count'     => $rawMemberCount,
                        'minimum_member_floor' => $minimumMemberFloor,
                        'min_items_per_day'    => $this->minItemsPerDay,
                        'raw_away_days'        => $awayDays,
                        'effective_away_days'  => $effectiveAwayDays,
                    ],
                );
            }

            return null;
        }

        $weekendAssessment = $this->evaluateWeekendGetaway(
            $dayKeys,
            $days,
            $baseAwayMap,
            $weekendHolidayFlags,
            $effectiveAwayDays,
            $nights,
        );

        $isWeekendGetaway        = $weekendAssessment['isWeekend'] && $weekendAssessment['exceptionApplies'];
        $weekendExceptionApplied = $weekendAssessment['exceptionApplies'];
        $weekendFlaggedDays      = $weekendAssessment['flaggedDays'];

        if ($effectiveAwayDays < $this->minAwayDays && $weekendExceptionApplied === false) {
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

        $classification = $this->classifyTrip($effectiveAwayDays, $awayDays, $nights, $maxDistanceValue, $isWeekendGetaway);
        if ($classification === 'none') {
            return null;
        }

        if (
            $classification === 'short_trip'
            && $awayDays >= $this->minAwayDays
            && $multiSpotDays >= 2
        ) {
            $classification = 'vacation';
        }

        $baseClassification = $classification;
        $classification      = $this->applyScoreThresholds($classification, $score);
        if ($classification === null) {
            return null;
        }

        if (
            $classification === 'short_trip'
            && $baseClassification === 'vacation'
            && $dayCount <= 2
            && $awayDays >= $this->minAwayDays
            && $multiSpotDays >= 2
        ) {
            $classification = 'vacation';
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

        if ($dayContext !== []) {
            foreach ($acceptedSummaries as $dayKey => &$summary) {
                $context = $dayContext[$dayKey] ?? null;
                if (is_array($summary) && is_array($context)) {
                    $summary['selectionContext'] = $context;
                }
            }

            unset($summary);
        }
        $preSelectionCount = $rawMemberCount;
        $storyline         = $this->resolveStoryline(
            $classification,
            $moveDays,
            $transitRatio,
            $multiSpotDays,
        );

        $selectionContext = [
            'away_days'       => $effectiveAwayDays,
            'nights'          => $nights,
            'weekend_getaway' => $isWeekendGetaway,
        ];

        $requestedProfile = null;
        if ($isWeekendGetaway && $this->weekendSelectionProfile !== null) {
            $requestedProfile = $this->weekendSelectionProfile;
        }

        $selectionProfileKey = $this->selectionProfiles->determineProfileKey(
            'vacation',
            $requestedProfile,
            $selectionContext,
        );
        if ($selectionProfileKey === 'vacation') {
            $selectionProfileKey = $this->defaultSelectionProfileKey;
        }

        $selectionOptions = $this->selectionProfiles->createOptions($selectionProfileKey);

        $selectionDecision = [
            'base'      => $this->defaultSelectionProfileKey,
            'requested' => $requestedProfile,
            'resolved'  => $selectionProfileKey,
            'context'   => $selectionContext,
        ];

        if ($this->monitoringEmitter !== null) {
            $startPayload = [
                'pre_count'                        => $preSelectionCount,
                'day_count'                        => $dayCount,
                'away_days'                        => $effectiveAwayDays,
                'raw_away_days'                    => $awayDays,
                'bridged_days'                     => $bridgedAwayDays,
                'staypoint_detected'               => $primaryStaypoint !== null,
                'storyline'                        => $storyline,
                'weekend_getaway'                  => $isWeekendGetaway,
                'weekend_exception'                => $weekendExceptionApplied,
                'selection_profile'                => $selectionProfileKey,
                'selection_target_total'           => $selectionOptions->targetTotal,
                'selection_minimum_total'          => $selectionOptions->minimumTotal,
                'selection_max_per_day'            => $selectionOptions->maxPerDay,
                'selection_max_per_staypoint'      => $selectionOptions->maxPerStaypoint,
                'selection_min_spacing_seconds'    => $selectionOptions->minSpacingSeconds,
                'selection_time_slot_hours'        => $selectionOptions->timeSlotHours,
                'selection_phash_min_hamming'      => $selectionOptions->phashMinHamming,
                'selection_phash_percentile'       => $selectionOptions->phashPercentile,
                'selection_core_day_bonus'         => $selectionOptions->coreDayBonus,
                'selection_peripheral_day_penalty' => $selectionOptions->peripheralDayPenalty,
                'selection_people_balance_weight'  => $selectionOptions->peopleBalanceWeight,
                'selection_people_balance_enabled' => $selectionOptions->enablePeopleBalance,
                'selection_spacing_progress_factor'=> $selectionOptions->spacingProgressFactor,
                'selection_cohort_repeat_penalty'  => $selectionOptions->cohortRepeatPenalty,
                'selection_video_bonus'            => $selectionOptions->videoBonus,
                'selection_face_bonus'             => $selectionOptions->faceBonus,
                'selection_selfie_penalty'         => $selectionOptions->selfiePenalty,
                'selection_quality_floor'          => $selectionOptions->qualityFloor,
                'selection_repeat_penalty'         => $selectionOptions->repeatPenalty,
                'selection_profile_decision'       => $selectionDecision,
                'raw_member_count'                 => $rawMemberCount,
                'minimum_member_floor'             => $minimumMemberFloor,
                'min_items_per_day'                => $this->minItemsPerDay,
            ];

            if ($primaryStaypoint !== null) {
                $startPayload['primary_staypoint_dwell_s'] = (int) $primaryStaypoint['dwell'];
            }

            $this->monitoringEmitter->emit('vacation_curation', 'selection_start', $startPayload);
        }

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

        $selectionTelemetry['storyline'] = $storyline;

        $dayCategorySummary = $this->summariseDayCategories($dayKeys, $dayContext);

        $missingCoreAllowed = $this->isMissingCoreAllowed($dayContext);

        if ($dayContext !== [] && $dayCategorySummary['core'] === 0 && $missingCoreAllowed === false) {
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
                        'reason'                   => 'missing_core_days',
                    ]
                );
            }

            return null;
        }

        $phashSummary  = $this->summarisePhashDistribution($selectionTelemetry);
        $peopleSummary = $this->summarisePeopleBalance($selectionTelemetry, $cohortMemberAggregate);
        $poiSummary    = $this->summarisePoiCoverage(
            $dayKeys,
            $baseAwayMap,
            $poiPresence,
            $dayContext,
            $poiTypeSamples,
            $tourismHits,
            $poiSamples,
            $tourismRatio,
        );
        $profileSummary = $this->summariseSelectionProfile($selectionProfileKey, $selectionOptions);

        $relaxationsApplied = array_values(array_unique(array_filter(
            $selectionTelemetry['relaxation_hints'],
            static fn ($hint): bool => is_string($hint) && $hint !== '',
        )));

        $dedupeRate = $preSelectionCount > 0
            ? $nearDupBlocked / $preSelectionCount
            : 0.0;

        $runMetrics = [
            'storyline'                   => $storyline,
            'run_length_days'             => $dayCount,
            'run_length_effective_days'   => $effectiveAwayDays,
            'run_length_nights'           => $nights,
            'core_day_count'              => $dayCategorySummary['core'],
            'peripheral_day_count'        => $dayCategorySummary['peripheral'],
            'core_day_ratio'              => $dayCount > 0 ? $dayCategorySummary['core'] / $dayCount : 0.0,
            'peripheral_day_ratio'        => $dayCount > 0 ? $dayCategorySummary['peripheral'] / $dayCount : 0.0,
            'phash_distribution'          => $phashSummary,
            'people_balance'              => $peopleSummary,
            'poi_coverage'                => $poiSummary,
            'selection_profile'           => $profileSummary,
            'selection_pre_count'         => $preSelectionCount,
            'selection_post_count'        => $selectedCount,
            'selection_average_spacing_seconds' => $averageSpacingSeconds,
            'selection_dedupe_rate'             => $dedupeRate,
            'selection_relaxations_applied'     => $relaxationsApplied,
            'selection_profile_decision'        => $selectionDecision,
            'raw_member_count'                  => $rawMemberCount,
            'minimum_member_floor'              => $minimumMemberFloor,
            'min_items_per_day'                 => $this->minItemsPerDay,
        ];

        $selectionTelemetry['run_metrics'] = $runMetrics;

        $this->emitRunMetrics($runMetrics);

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

        $timeRange = $this->determineRunTimeRange($dayKeys, $days, $rawMembers);
        if ($timeRange === null) {
            $timeRange = MediaMath::timeRange($curatedMembers);
        }

        $memberIds = array_map(
            static fn (Media $media): int => $media->getId(),
            $curatedMembers
        );

        // Raw member aggregates continue to inform scoring metrics, while
        // curated members drive presentation metadata below.
        $place           = $this->locationHelper->majorityLabel($curatedMembers);
        $placeComponents = $this->locationHelper->majorityLocationComponents($curatedMembers);

        $classificationLabels = [
            'vacation'        => 'Urlaub',
            'short_trip'      => 'Kurztrip',
            'weekend_getaway' => 'Wochenendtrip',
            'day_trip'        => 'Tagesausflug',
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
            'raw_member_count'         => $rawMemberCount,
            'minimum_member_floor'     => $minimumMemberFloor,
            'min_items_per_day'        => $this->minItemsPerDay,
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
            'weekend_getaway'          => $isWeekendGetaway,
            'weekend_exception_applied' => $weekendExceptionApplied,
            'weekend_flagged_days'     => $weekendFlaggedDays,
            'selection_profile'        => $selectionProfileKey,
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

        if ($dayContext !== []) {
            $params['day_segments'] = $this->normaliseDayContext($dayKeys, $dayContext);
        }

        $params['storyline'] = $storyline;

        $params['member_selection'] = [
            'storyline' => $storyline,
            'counts' => [
                'raw'     => $preSelectionCount,
                'curated' => $selectedCount,
                'pre'     => $preSelectionCount,
                'post'    => $selectedCount,
                'dropped' => $droppedCount,
                'minimum_floor' => $minimumMemberFloor,
            ],
            'near_duplicates' => [
                'blocked'      => $nearDupBlocked,
                'replacements' => $nearDupReplaced,
            ],
            'spacing' => [
                'average_seconds' => $averageSpacingSeconds,
                'rejections'      => $spacingRejections,
            ],
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
                'core_day_bonus'      => $selectionOptions->coreDayBonus,
                'peripheral_day_penalty' => $selectionOptions->peripheralDayPenalty,
                'phash_percentile'    => $selectionOptions->phashPercentile,
                'spacing_progress_factor' => $selectionOptions->spacingProgressFactor,
                'cohort_repeat_penalty'    => $selectionOptions->cohortRepeatPenalty,
            ],
            'selection_profile' => $selectionProfileKey,
            'decision' => $selectionDecision,
        ];

        $memberQuality = $params['member_quality'] ?? [];
        if (!is_array($memberQuality)) {
            $memberQuality = [];
        }

        $memberQuality['ordered'] = $memberIds;

        $summary = $memberQuality['summary'] ?? [];
        if (!is_array($summary)) {
            $summary = [];
        }

        $summary['selection_counts'] = [
            'raw'     => $preSelectionCount,
            'curated' => $selectedCount,
            'dropped' => $droppedCount,
            'minimum_floor' => $minimumMemberFloor,
        ];
        $summary['selection_per_day_distribution'] = $orderedDistribution;
        $summary['selection_per_bucket_distribution'] = $selectionTelemetry['per_bucket_distribution'] ?? [];
        $summary['selection_spacing'] = [
            'average_seconds' => $averageSpacingSeconds,
            'rejections'      => $spacingRejections,
        ];
        $summary['selection_near_duplicates'] = [
            'blocked'      => $nearDupBlocked,
            'replacements' => $nearDupReplaced,
        ];
        $summary['selection_run_metrics'] = $runMetrics;
        $summary['selection_storyline'] = $storyline;
        $summary['selection_profile'] = $selectionProfileKey;
        $summary['selection_profile_decision'] = $selectionDecision;
        $summary['selection_telemetry'] = $selectionTelemetry;

        $memberQuality['summary'] = $summary;
        $params['member_quality'] = $memberQuality;

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

        $rawMemberIds = array_map(
            static fn (Media $media): int => $media->getId(),
            $rawMembers,
        );

        $draft = new ClusterDraft(
            algorithm: 'vacation',
            params: $params,
            centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
            members: $rawMemberIds,
            storyline: $storyline,
        );

        $storyTitle = $this->storyTitleBuilder->build($draft);
        $draft->setParam('vacation_title', $storyTitle['title']);
        $draft->setParam('vacation_subtitle', $storyTitle['subtitle']);

        return $draft;
    }

    /**
     * @param array<string, array{category:string}> $dayContext
     */
    private function isMissingCoreAllowed(array $dayContext): bool
    {
        if (self::MISSING_CORE_CATEGORY_EXCEPTIONS === []) {
            return false;
        }

        foreach ($dayContext as $context) {
            if (!is_array($context)) {
                continue;
            }

            $category = $context['category'] ?? null;
            if (!is_string($category)) {
                continue;
            }

            if (in_array($category, self::MISSING_CORE_CATEGORY_EXCEPTIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string>                                               $dayKeys
     * @param array<string, array{category:string}>                      $dayContext
     *
     * @return array{core:int,peripheral:int}
     */
    private function summariseDayCategories(array $dayKeys, array $dayContext): array
    {
        $core       = 0;
        $peripheral = 0;

        foreach ($dayKeys as $key) {
            $context  = $dayContext[$key] ?? null;
            $category = 'peripheral';

            if (is_array($context)) {
                $candidate = $context['category'] ?? 'peripheral';
                if (is_string($candidate) && $candidate !== '') {
                    $category = $candidate;
                }
            }

            if ($category === 'core') {
                ++$core;
            } else {
                ++$peripheral;
            }
        }

        return [
            'core'       => $core,
            'peripheral' => $peripheral,
        ];
    }

    /**
     * @param array<string, mixed> $selectionTelemetry
     *
     * @return array{count:int,average:float,median:float,p90:float,p99:float,min:float,max:float}
     */
    private function summarisePhashDistribution(array $selectionTelemetry): array
    {
        $metrics = $selectionTelemetry['metrics'] ?? null;
        if (!is_array($metrics)) {
            return [
                'count'   => 0,
                'average' => 0.0,
                'median'  => 0.0,
                'p90'     => 0.0,
                'p99'     => 0.0,
                'min'     => 0.0,
                'max'     => 0.0,
            ];
        }

        $distances = $metrics['phash_distances'] ?? null;
        if (!is_array($distances)) {
            return [
                'count'   => 0,
                'average' => 0.0,
                'median'  => 0.0,
                'p90'     => 0.0,
                'p99'     => 0.0,
                'min'     => 0.0,
                'max'     => 0.0,
            ];
        }

        $samples = [];
        foreach ($distances as $value) {
            if (is_int($value) || is_float($value)) {
                $samples[] = (float) $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $samples[] = (float) $value;
            }
        }

        if ($samples === []) {
            return [
                'count'   => 0,
                'average' => 0.0,
                'median'  => 0.0,
                'p90'     => 0.0,
                'p99'     => 0.0,
                'min'     => 0.0,
                'max'     => 0.0,
            ];
        }

        sort($samples, SORT_NUMERIC);

        $count   = count($samples);
        $average = array_sum($samples) / $count;

        return [
            'count'   => $count,
            'average' => $average,
            'median'  => $this->percentile($samples, 0.5),
            'p90'     => $this->percentile($samples, 0.9),
            'p99'     => $this->percentile($samples, 0.99),
            'min'     => $samples[0],
            'max'     => $samples[$count - 1],
        ];
    }

    /**
     * @param list<float> $sortedSamples
     */
    private function percentile(array $sortedSamples, float $ratio): float
    {
        if ($sortedSamples === []) {
            return 0.0;
        }

        if ($ratio <= 0.0) {
            return $sortedSamples[0];
        }

        $maxIndex = count($sortedSamples) - 1;
        if ($ratio >= 1.0) {
            return $sortedSamples[$maxIndex];
        }

        $position   = $ratio * $maxIndex;
        $lowerIndex = (int) floor($position);
        $upperIndex = (int) ceil($position);

        if ($lowerIndex === $upperIndex) {
            return $sortedSamples[$lowerIndex];
        }

        $lowerValue = $sortedSamples[$lowerIndex];
        $upperValue = $sortedSamples[$upperIndex];
        $fraction   = $position - $lowerIndex;

        return $lowerValue + (($upperValue - $lowerValue) * $fraction);
    }

    /**
     * @param array<string, mixed> $selectionTelemetry
     * @param array<int, int>      $cohortMemberAggregate
     *
     * @return array<string, mixed>
     */
    private function summarisePeopleBalance(array $selectionTelemetry, array $cohortMemberAggregate): array
    {
        $countsRaw = $selectionTelemetry['people_balance_counts'] ?? null;
        $counts    = [];

        if (is_array($countsRaw)) {
            foreach ($countsRaw as $person => $value) {
                if (!is_string($person) || $person === '') {
                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $counts[$person] = (int) $value;
                } elseif (is_string($value) && is_numeric($value)) {
                    $counts[$person] = (int) $value;
                }
            }
        }

        $total    = 0;
        $maxCount = 0;

        foreach ($counts as $value) {
            $total += $value;
            if ($value > $maxCount) {
                $maxCount = $value;
            }
        }

        $dominantShare = $total > 0 ? $maxCount / $total : 0.0;

        $enabled = (bool) ($selectionTelemetry['people_balance_enabled'] ?? false);
        $weight  = isset($selectionTelemetry['people_balance_weight'])
            ? (float) $selectionTelemetry['people_balance_weight']
            : 0.0;
        $repeatPenalty = isset($selectionTelemetry['people_balance_repeat_penalty'])
            ? (float) $selectionTelemetry['people_balance_repeat_penalty']
            : 0.0;

        $targetCapRaw = $selectionTelemetry['people_balance_target_cap'] ?? null;
        $targetCap    = null;
        if (is_int($targetCapRaw)) {
            $targetCap = $targetCapRaw;
        } elseif (is_float($targetCapRaw)) {
            $targetCap = (int) $targetCapRaw;
        } elseif (is_string($targetCapRaw) && is_numeric($targetCapRaw)) {
            $targetCap = (int) $targetCapRaw;
        }

        return [
            'enabled'        => $enabled,
            'weight'         => $weight,
            'repeat_penalty' => $repeatPenalty,
            'target_cap'     => $targetCap,
            'considered'     => (int) ($selectionTelemetry['people_balance_considered'] ?? 0),
            'penalized'      => (int) ($selectionTelemetry['people_balance_penalized'] ?? 0),
            'bonuses'        => (int) ($selectionTelemetry['people_balance_bonuses'] ?? 0),
            'rejected'       => (int) ($selectionTelemetry['people_balance_rejected'] ?? 0),
            'accepted'       => (int) ($selectionTelemetry['people_balance_accepted'] ?? 0),
            'unique_people'  => count($counts),
            'total_samples'  => $total,
            'dominant_share' => $dominantShare,
            'cohort_tracked' => count($cohortMemberAggregate),
        ];
    }

    /**
     * @param list<string>                      $dayKeys
     * @param array<string, bool>               $baseAwayMap
     * @param array<string, bool>               $poiPresence
     * @param array<string, array{category:string}> $dayContext
     * @param array<string, bool>               $poiTypeSamples
     *
     * @return array<string, float|int>
     */
    private function summarisePoiCoverage(
        array $dayKeys,
        array $baseAwayMap,
        array $poiPresence,
        array $dayContext,
        array $poiTypeSamples,
        int $tourismHits,
        int $poiSamples,
        float $tourismRatio,
    ): array {
        $awayDayCount      = 0;
        $poiDayCount       = 0;
        $corePoiDays       = 0;
        $peripheralPoiDays = 0;

        foreach ($dayKeys as $key) {
            $isAway = $baseAwayMap[$key] ?? false;
            if ($isAway) {
                ++$awayDayCount;
            } else {
                continue;
            }

            $hasPoi = $poiPresence[$key] ?? false;
            if ($hasPoi) {
                ++$poiDayCount;

                $category = 'peripheral';
                $context  = $dayContext[$key] ?? null;
                if (is_array($context)) {
                    $candidate = $context['category'] ?? 'peripheral';
                    if (is_string($candidate) && $candidate !== '') {
                        $category = $candidate;
                    }
                }

                if ($category === 'core') {
                    ++$corePoiDays;
                } else {
                    ++$peripheralPoiDays;
                }
            }
        }

        $poiDayRatio = $awayDayCount > 0 ? $poiDayCount / $awayDayCount : 0.0;

        return [
            'away_day_count'      => $awayDayCount,
            'poi_day_count'       => $poiDayCount,
            'poi_day_ratio'       => $poiDayRatio,
            'poi_core_days'       => $corePoiDays,
            'poi_peripheral_days' => $peripheralPoiDays,
            'poi_type_count'      => count($poiTypeSamples),
            'tourism_hits'        => $tourismHits,
            'poi_samples'         => $poiSamples,
            'tourism_ratio'       => $tourismRatio,
        ];
    }

    private function summariseSelectionProfile(string $profileKey, VacationSelectionOptions $options): array
    {
        return [
            'profile_key'                => $profileKey,
            'target_total'               => $options->targetTotal,
            'minimum_total'              => $options->minimumTotal,
            'max_per_day'                => $options->maxPerDay,
            'max_per_staypoint'          => $options->maxPerStaypoint,
            'min_spacing_seconds'        => $options->minSpacingSeconds,
            'time_slot_hours'            => $options->timeSlotHours,
            'phash_min_hamming'          => $options->phashMinHamming,
            'phash_percentile'           => $options->phashPercentile,
            'core_day_bonus'             => $options->coreDayBonus,
            'peripheral_day_penalty'     => $options->peripheralDayPenalty,
            'people_balance_weight'      => $options->peopleBalanceWeight,
            'people_balance_enabled'     => $options->enablePeopleBalance,
            'spacing_progress_factor'    => $options->spacingProgressFactor,
            'cohort_repeat_penalty'      => $options->cohortRepeatPenalty,
            'video_bonus'                => $options->videoBonus,
            'face_bonus'                 => $options->faceBonus,
            'selfie_penalty'             => $options->selfiePenalty,
            'quality_floor'              => $options->qualityFloor,
            'repeat_penalty'             => $options->repeatPenalty,
        ];
    }

    /**
     * @param array<string, mixed> $runMetrics
     */
    private function emitRunMetrics(array $runMetrics): void
    {
        if ($this->monitoringEmitter === null) {
            return;
        }

        $phash   = $runMetrics['phash_distribution'];
        $people  = $runMetrics['people_balance'];
        $poi     = $runMetrics['poi_coverage'];
        $profile = $runMetrics['selection_profile'];

        $context = [
            'storyline'                        => $runMetrics['storyline'],
            'run_length_days'                  => $runMetrics['run_length_days'],
            'run_length_effective_days'        => $runMetrics['run_length_effective_days'],
            'run_length_nights'                => $runMetrics['run_length_nights'],
            'core_day_count'                   => $runMetrics['core_day_count'],
            'peripheral_day_count'             => $runMetrics['peripheral_day_count'],
            'core_day_ratio'                   => round((float) $runMetrics['core_day_ratio'], 3),
            'peripheral_day_ratio'             => round((float) $runMetrics['peripheral_day_ratio'], 3),
            'phash_sample_count'               => $phash['count'],
            'phash_avg_distance'               => round((float) $phash['average'], 3),
            'phash_median_distance'            => round((float) $phash['median'], 3),
            'phash_p90_distance'               => round((float) $phash['p90'], 3),
            'phash_p99_distance'               => round((float) $phash['p99'], 3),
            'phash_min_distance'               => round((float) $phash['min'], 3),
            'phash_max_distance'               => round((float) $phash['max'], 3),
            'people_unique_count'              => $people['unique_people'],
            'people_total_samples'             => $people['total_samples'],
            'people_dominant_share'            => round((float) $people['dominant_share'], 3),
            'people_penalized'                 => $people['penalized'],
            'people_bonuses'                   => $people['bonuses'],
            'people_rejected'                  => $people['rejected'],
            'people_accepted'                  => $people['accepted'],
            'people_considered'                => $people['considered'],
            'people_enabled'                   => $people['enabled'],
            'people_weight'                    => round((float) $people['weight'], 3),
            'people_repeat_penalty'            => round((float) $people['repeat_penalty'], 3),
            'people_cohort_tracked'            => $people['cohort_tracked'],
            'poi_day_count'                    => $poi['poi_day_count'],
            'poi_day_ratio'                    => round((float) $poi['poi_day_ratio'], 3),
            'poi_core_days'                    => $poi['poi_core_days'],
            'poi_peripheral_days'              => $poi['poi_peripheral_days'],
            'poi_type_count'                   => $poi['poi_type_count'],
            'poi_tourism_hits'                 => $poi['tourism_hits'],
            'poi_samples'                      => $poi['poi_samples'],
            'poi_tourism_ratio'                => round((float) $poi['tourism_ratio'], 3),
            'selection_profile'                => $profile['profile_key'],
            'selection_target_total'           => $profile['target_total'],
            'selection_minimum_total'          => $profile['minimum_total'],
            'selection_max_per_day'            => $profile['max_per_day'],
            'selection_max_per_staypoint'      => $profile['max_per_staypoint'],
            'selection_min_spacing_seconds'    => $profile['min_spacing_seconds'],
            'selection_time_slot_hours'        => $profile['time_slot_hours'],
            'selection_phash_min_hamming'      => $profile['phash_min_hamming'],
            'selection_phash_percentile'       => $profile['phash_percentile'],
            'selection_core_day_bonus'         => $profile['core_day_bonus'],
            'selection_peripheral_day_penalty' => $profile['peripheral_day_penalty'],
            'selection_people_balance_weight'  => $profile['people_balance_weight'],
            'selection_people_balance_enabled' => $profile['people_balance_enabled'],
            'selection_spacing_progress_factor'=> $profile['spacing_progress_factor'],
            'selection_cohort_repeat_penalty'  => $profile['cohort_repeat_penalty'],
            'selection_video_bonus'            => round((float) $profile['video_bonus'], 3),
            'selection_face_bonus'             => round((float) $profile['face_bonus'], 3),
            'selection_selfie_penalty'         => round((float) $profile['selfie_penalty'], 3),
            'selection_quality_floor'          => round((float) $profile['quality_floor'], 3),
            'selection_repeat_penalty'         => round((float) $profile['repeat_penalty'], 3),
            'selection_pre_count'              => $runMetrics['selection_pre_count'],
            'selection_post_count'             => $runMetrics['selection_post_count'],
            'selection_average_spacing_seconds'=> round((float) $runMetrics['selection_average_spacing_seconds'], 3),
            'selection_dedupe_rate'            => round((float) $runMetrics['selection_dedupe_rate'], 3),
            'selection_relaxations_applied'    => $runMetrics['selection_relaxations_applied'],
            'selection_profile_decision'       => $runMetrics['selection_profile_decision'],
            'raw_member_count'                 => $runMetrics['raw_member_count'],
            'minimum_member_floor'             => $runMetrics['minimum_member_floor'],
            'min_items_per_day'                => $runMetrics['min_items_per_day'],
        ];

        if ($people['target_cap'] !== null) {
            $context['people_target_cap'] = (int) $people['target_cap'];
        }

        $this->monitoringEmitter->emit('cluster.vacation', 'run_metrics', $context);
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
                $dayIndexCounts = $index->getCounts();
                foreach ($dayIndexCounts as $key => $count) {
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

                $staypointCounts = $summary['staypointCounts'] ?? [];
                if (is_array($staypointCounts)) {
                    foreach ($staypointCounts as $key => $count) {
                        if (!is_string($key)) {
                            continue;
                        }

                        $normalizedCount = (int) $count;
                        $baseCount       = (int) ($dayIndexCounts[$key] ?? 0);
                        if ($normalizedCount <= $baseCount) {
                            continue;
                        }

                        $counts[$key] = ($counts[$key] ?? 0) + ($normalizedCount - $baseCount);
                    }
                }

                continue;
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
     * @param array<string, mixed> $config
     *
     * @return array{enabled:bool,min_nights:int,max_nights:int,min_flagged_days:int,require_saturday:bool,require_sunday:bool,require_weekend_flag:bool}
     */
    private function sanitizeWeekendExceptionConfig(array $config): array
    {
        $enabled = isset($config['enabled']) ? (bool) $config['enabled'] : true;
        $minNights = isset($config['min_nights']) ? (int) $config['min_nights'] : 1;
        if ($minNights < 1) {
            $minNights = 1;
        }

        $maxNights = isset($config['max_nights']) ? (int) $config['max_nights'] : 3;
        if ($maxNights < $minNights) {
            $maxNights = $minNights;
        }

        $minFlaggedDays = isset($config['min_flagged_days']) ? (int) $config['min_flagged_days'] : 2;
        if ($minFlaggedDays < 0) {
            $minFlaggedDays = 0;
        }

        $requireSaturday    = isset($config['require_saturday']) ? (bool) $config['require_saturday'] : true;
        $requireSunday      = isset($config['require_sunday']) ? (bool) $config['require_sunday'] : true;
        $requireWeekendFlag = isset($config['require_weekend_flag']) ? (bool) $config['require_weekend_flag'] : true;

        return [
            'enabled'             => $enabled,
            'min_nights'          => $minNights,
            'max_nights'          => $maxNights,
            'min_flagged_days'    => $minFlaggedDays,
            'require_saturday'    => $requireSaturday,
            'require_sunday'      => $requireSunday,
            'require_weekend_flag' => $requireWeekendFlag,
        ];
    }

    /**
     * @param list<string>                 $dayKeys
     * @param array<string, array<string,mixed>> $days
     * @param array<string, bool>          $baseAwayMap
     * @param array<string, bool>          $weekendHolidayFlags
     *
     * @return array{isWeekend:bool,exceptionApplies:bool,flaggedDays:int}
     */
    private function evaluateWeekendGetaway(
        array $dayKeys,
        array $days,
        array $baseAwayMap,
        array $weekendHolidayFlags,
        int $effectiveAwayDays,
        int $nights,
    ): array {
        $config = $this->weekendExceptionConfig;

        if ($config['enabled'] === false) {
            return ['isWeekend' => false, 'exceptionApplies' => false, 'flaggedDays' => 0];
        }

        if ($nights < $config['min_nights'] || $nights > $config['max_nights']) {
            return ['isWeekend' => false, 'exceptionApplies' => false, 'flaggedDays' => 0];
        }

        $hasSaturday = false;
        $hasSunday   = false;
        $flaggedDays = 0;

        foreach ($dayKeys as $key) {
            $summary = $days[$key] ?? null;
            if ($summary === null) {
                continue;
            }

            $isAway = $baseAwayMap[$key] ?? false;
            if ($isAway === false) {
                continue;
            }

            $weekday = (int) ($summary['weekday'] ?? 0);
            if ($weekday === 6) {
                $hasSaturday = true;
            } elseif ($weekday === 7) {
                $hasSunday = true;
            }

            if (($weekendHolidayFlags[$key] ?? false) === true) {
                ++$flaggedDays;
            }
        }

        if ($config['require_saturday'] && $hasSaturday === false) {
            return ['isWeekend' => false, 'exceptionApplies' => false, 'flaggedDays' => $flaggedDays];
        }

        if ($config['require_sunday'] && $hasSunday === false) {
            return ['isWeekend' => false, 'exceptionApplies' => false, 'flaggedDays' => $flaggedDays];
        }

        if ($flaggedDays < $config['min_flagged_days']) {
            if ($config['require_weekend_flag'] || $config['min_flagged_days'] > 0) {
                return ['isWeekend' => false, 'exceptionApplies' => false, 'flaggedDays' => $flaggedDays];
            }
        }

        $exceptionApplies = $effectiveAwayDays < $this->minAwayDays;

        return [
            'isWeekend'        => true,
            'exceptionApplies' => $exceptionApplies,
            'flaggedDays'      => $flaggedDays,
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
     * @param list<string> $dayKeys
     * @param array<string, array<string, mixed>> $days
     * @param list<Media> $rawMembers
     *
     * @return array{from:int,to:int}|null
     */
    private function determineRunTimeRange(array $dayKeys, array $days, array $rawMembers): ?array
    {
        $memberRange = $rawMembers !== [] ? MediaMath::timeRange($rawMembers) : null;
        $summaryRange = $this->timeRangeFromSummaries($dayKeys, $days);

        if ($memberRange === null) {
            return $summaryRange;
        }

        if ($summaryRange === null) {
            return $memberRange;
        }

        return [
            'from' => min($memberRange['from'], $summaryRange['from']),
            'to'   => max($memberRange['to'], $summaryRange['to']),
        ];
    }

    /**
     * @param list<string> $dayKeys
     * @param array<string, array<string, mixed>> $days
     *
     * @return array{from:int,to:int}|null
     */
    private function timeRangeFromSummaries(array $dayKeys, array $days): ?array
    {
        $from = null;
        $to   = null;

        foreach ($dayKeys as $dayKey) {
            $summary = $days[$dayKey] ?? null;
            if (!is_array($summary)) {
                continue;
            }

            $range = $this->timeRangeFromSummary($summary);
            if ($range === null) {
                continue;
            }

            if ($from === null || $range['from'] < $from) {
                $from = $range['from'];
            }

            if ($to === null || $range['to'] > $to) {
                $to = $range['to'];
            }
        }

        if ($from === null || $to === null) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array{from:int,to:int}|null
     */
    private function timeRangeFromSummary(array $summary): ?array
    {
        $timestamps = [];

        $members = $summary['members'] ?? [];
        if (is_array($members)) {
            foreach ($members as $media) {
                if (!$media instanceof Media) {
                    continue;
                }

                $takenAt = $media->getTakenAt();
                if ($takenAt instanceof DateTimeImmutable) {
                    $timestamps[] = $takenAt->getTimestamp();
                }
            }
        }

        $gpsMembers = $summary['gpsMembers'] ?? [];
        if (is_array($gpsMembers)) {
            foreach ($gpsMembers as $media) {
                if (!$media instanceof Media) {
                    continue;
                }

                $takenAt = $media->getTakenAt();
                if ($takenAt instanceof DateTimeImmutable) {
                    $timestamps[] = $takenAt->getTimestamp();
                }
            }
        }

        if ($timestamps !== []) {
            sort($timestamps, SORT_NUMERIC);

            return [
                'from' => $timestamps[0],
                'to'   => $timestamps[count($timestamps) - 1],
            ];
        }

        $staypoints = $summary['staypoints'] ?? null;
        if (is_array($staypoints)) {
            $range = $this->timeRangeFromStaypoints($staypoints);
            if ($range !== null) {
                return $range;
            }
        }

        $dominant = $summary['dominantStaypoints'] ?? null;
        if (is_array($dominant)) {
            $range = $this->timeRangeFromStaypoints($dominant);
            if ($range !== null) {
                return $range;
            }
        }

        $date = $summary['date'] ?? null;
        if (!is_string($date) || $date === '') {
            return null;
        }

        $timezoneId = $summary['localTimezoneIdentifier'] ?? null;
        try {
            $timezone = is_string($timezoneId) && $timezoneId !== ''
                ? new DateTimeZone($timezoneId)
                : new DateTimeZone('UTC');

            $start = new DateTimeImmutable($date . ' 00:00:00', $timezone);
            $end   = $start->modify('+1 day -1 second');
        } catch (Exception) {
            return null;
        }

        if (!$end instanceof DateTimeImmutable) {
            $end = $start->modify('+1 day');
            if (!$end instanceof DateTimeImmutable) {
                $end = $start;
            }
        }

        return [
            'from' => $start->getTimestamp(),
            'to'   => $end->getTimestamp(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $staypoints
     *
     * @return array{from:int,to:int}|null
     */
    private function timeRangeFromStaypoints(array $staypoints): ?array
    {
        if ($staypoints === []) {
            return null;
        }

        $from = null;
        $to   = null;

        foreach ($staypoints as $staypoint) {
            if (!is_array($staypoint)) {
                continue;
            }

            $startTs = $this->normalizeTimestamp($staypoint['start'] ?? null);
            $endTs   = $this->normalizeTimestamp($staypoint['end'] ?? null);

            if ($startTs === null && $endTs === null) {
                $dwell = $staypoint['dwell'] ?? ($staypoint['dwellSeconds'] ?? null);
                if (is_numeric($dwell) && (int) $dwell > 0) {
                    $startTs = $this->normalizeTimestamp($staypoint['timestamp'] ?? null);
                    if ($startTs !== null) {
                        $endTs = $startTs + (int) $dwell;
                    }
                }
            }

            if ($startTs === null && $endTs !== null) {
                $dwell = $staypoint['dwell'] ?? ($staypoint['dwellSeconds'] ?? null);
                if (is_numeric($dwell)) {
                    $startTs = max(0, $endTs - (int) $dwell);
                }
            } elseif ($endTs === null && $startTs !== null) {
                $dwell = $staypoint['dwell'] ?? ($staypoint['dwellSeconds'] ?? null);
                if (is_numeric($dwell)) {
                    $endTs = $startTs + (int) $dwell;
                }
            }

            if ($startTs === null && $endTs === null) {
                continue;
            }

            if ($startTs === null) {
                $startTs = $endTs;
            }

            if ($endTs === null) {
                $endTs = $startTs;
            }

            if ($from === null || $startTs < $from) {
                $from = $startTs;
            }

            if ($to === null || $endTs > $to) {
                $to = $endTs;
            }
        }

        if ($from === null || $to === null) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param int|float|string|DateTimeImmutable|null $value
     */
    private function normalizeTimestamp(int|float|string|DateTimeImmutable|null $value): ?int
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
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

            if ($previousAway || $nextAway) {
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

    private function classifyTrip(
        int $effectiveAwayDays,
        int $rawAwayDays,
        int $nights,
        float $distanceKm,
        bool $weekendGetaway,
    ): string {
        if ($effectiveAwayDays <= 0) {
            return 'none';
        }

        if ($weekendGetaway) {
            if ($nights === 0) {
                return 'day_trip';
            }

            return 'weekend_getaway';
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

        if ($classification === 'weekend_getaway') {
            return $prefix . '.weekend';
        }

        if ($transitRatio >= 0.45 && $moveDays >= 2) {
            return $prefix . '.transit';
        }

        if ($multiSpotDays >= 5) {
            return $prefix . '.explorer';
        }

        return $prefix . '.extended';
    }

    private function applyScoreThresholds(string $classification, float $score): ?string
    {
        $thresholds = [
            'vacation'        => 7.0,
            'short_trip'      => 5.5,
            'weekend_getaway' => 5.0,
            'day_trip'        => 4.0,
        ];

        $fallbacks = [
            'vacation'        => ['vacation', 'short_trip', 'weekend_getaway', 'day_trip'],
            'short_trip'      => ['short_trip', 'day_trip'],
            'weekend_getaway' => ['weekend_getaway', 'day_trip'],
            'day_trip'        => ['day_trip'],
        ];

        if (!isset($fallbacks[$classification])) {
            return null;
        }

        foreach ($fallbacks[$classification] as $candidate) {
            $threshold = $thresholds[$candidate] ?? null;
            if ($threshold === null) {
                continue;
            }

            if ($score >= $threshold) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveMinimumMemberFloor(int $awayDays): int
    {
        $normalizedAwayDays = max(0, $awayDays);
        if ($normalizedAwayDays <= 2) {
            $adaptiveFloor = (int) ceil($this->minItemsPerDay * max(1, $normalizedAwayDays) * 0.5);
        } elseif ($normalizedAwayDays <= 4) {
            $adaptiveFloor = (int) ceil($this->minItemsPerDay * $normalizedAwayDays * 0.7);
        } else {
            $adaptiveFloor = (int) ceil($this->minItemsPerDay * $normalizedAwayDays * 0.6);
        }
        $adaptiveFloor      = max($this->minimumMemberFloor, $adaptiveFloor);

        if ($this->minMembers > 0) {
            $adaptiveFloor = max($adaptiveFloor, $this->minMembers);
        }

        return $adaptiveFloor;
    }

    /**
     * @param list<string> $dayKeys
     * @param array<string, array{score:float|int|string,category:string,duration:int|string|null,metrics:array<string,float|int|string>|null}> $dayContext
     *
     * @return array<string, array{score:float,category:string,duration:int|null,metrics:array<string,float>}>
     */
    private function normaliseDayContext(array $dayKeys, array $dayContext): array
    {
        $ordered = [];

        foreach ($dayKeys as $key) {
            $context = $dayContext[$key] ?? null;
            if (!is_array($context)) {
                continue;
            }

            $score    = $context['score'] ?? 0.0;
            $category = $context['category'] ?? 'peripheral';
            $duration = $context['duration'] ?? null;
            $metrics  = $context['metrics'] ?? [];

            if (!is_string($category) || $category === '') {
                $category = 'peripheral';
            }

            $ordered[$key] = [
                'score'    => $this->normaliseFloat($score),
                'category' => $category,
                'duration' => $this->normaliseDuration($duration),
                'metrics'  => $this->normaliseDayMetrics($metrics),
            ];
        }

        return $ordered;
    }

    private function normaliseFloat(float|int|string $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function normaliseDuration(int|string|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && is_numeric($value)) {
            $duration = (int) $value;

            return $duration >= 0 ? $duration : null;
        }

        return null;
    }

    /**
     * @param array<string, float|int|string>|null $metrics
     *
     * @return array<string, float>
     */
    private function normaliseDayMetrics(?array $metrics): array
    {
        if ($metrics === null) {
            return [];
        }

        $result = [];
        foreach ($metrics as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_float($value) || is_int($value)) {
                $result[$key] = (float) $value;

                continue;
            }

            if (is_string($value) && is_numeric($value)) {
                $result[$key] = (float) $value;
            }
        }

        return $result;
    }
}
