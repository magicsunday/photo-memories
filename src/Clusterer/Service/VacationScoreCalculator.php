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
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Contract\VacationScoreCalculatorInterface;
use MagicSunday\Memories\Clusterer\Support\VacationTimezoneTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Clusterer\Scoring\HolidayResolverInterface;
use MagicSunday\Memories\Service\Clusterer\Scoring\NullHolidayResolver;
use MagicSunday\Memories\Utility\LocationHelper;
use MagicSunday\Memories\Utility\MediaMath;

use function abs;
use function array_keys;
use function array_map;
use function count;
use function explode;
use function implode;
use function in_array;
use function log;
use function max;
use function min;
use function preg_replace;
use function round;
use function sort;
use function str_replace;
use function ucwords;
use function usort;

use const SORT_NUMERIC;
use const SORT_STRING;

/**
 * Calculates vacation cluster drafts and scores.
 */
final class VacationScoreCalculator implements VacationScoreCalculatorInterface
{
    use VacationTimezoneTrait;

    private const float WEEKEND_OR_HOLIDAY_BONUS = 0.35;
    private const int DAY_SLOT_HOURS = 6;
    private const float QUALITY_BASELINE_MEGAPIXELS = 12.0;

    /**
     * @param float $movementThresholdKm Minimum travel distance to count as move day.
     */
    public function __construct(
        private LocationHelper $locationHelper,
        private HolidayResolverInterface $holidayResolver = new NullHolidayResolver(),
        private string $timezone = 'Europe/Berlin',
        private float $movementThresholdKm = 35.0,
    ) {
        if ($this->timezone === '') {
            throw new InvalidArgumentException('timezone must not be empty.');
        }

        if ($this->movementThresholdKm <= 0.0) {
            throw new InvalidArgumentException('movementThresholdKm must be > 0.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function buildDraft(array $dayKeys, array $days, array $home): ?ClusterDraft
    {
        if ($dayKeys === []) {
            return null;
        }

        $members = [];
        /** @var array<string, list<Media>> $dayMembers */
        $dayMembers = [];
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
                if (!isset($dayMembers[$key])) {
                    $dayMembers[$key] = [];
                }

                $dayMembers[$key][] = $media;
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

        $orderedMembers = $this->buildInterleavedMembers($dayKeys, $dayMembers, $days, $home);
        if ($orderedMembers === [] || count($orderedMembers) !== count($members)) {
            $orderedMembers = $members;
        }

        $timeRange = MediaMath::timeRange($members);

        $memberIds = array_map(
            static fn (Media $media): int => $media->getId(),
            $orderedMembers
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

    /**
     * @param list<string>                                                                       $dayKeys
     * @param array<string, list<Media>>                                                         $dayMembers
     * @param array<string, array{members:list<Media>,localTimezoneIdentifier:string,localTimezoneOffset:int|null,timezoneOffsets:array<int,int>,date:string}> $days
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     *
     * @return list<Media>
     */
    private function buildInterleavedMembers(array $dayKeys, array $dayMembers, array $days, array $home): array
    {
        if ($dayKeys === []) {
            return [];
        }

        /** @var array<string, list<Media>> $queues */
        $queues = [];
        foreach ($dayKeys as $dayKey) {
            $members = $dayMembers[$dayKey] ?? null;
            if ($members === null) {
                continue;
            }

            $summary = $days[$dayKey] ?? null;
            if ($summary === null) {
                continue;
            }

            $queue = $this->buildPrioritizedDayQueue($summary, $home);
            if ($queue !== []) {
                $queues[$dayKey] = $queue;
            }
        }

        if ($queues === []) {
            return [];
        }

        $interleaved = [];
        do {
            $added = false;
            foreach ($dayKeys as $dayKey) {
                $queue = $queues[$dayKey] ?? null;
                if ($queue === null || $queue === []) {
                    continue;
                }

                $media = array_shift($queues[$dayKey]);
                if ($media instanceof Media) {
                    $interleaved[] = $media;
                    $added = true;
                }
            }
        } while ($added);

        return $interleaved;
    }

    /**
     * @param array{members:list<Media>,localTimezoneIdentifier:string,localTimezoneOffset:int|null,timezoneOffsets:array<int,int>,date:string} $summary
     * @param array{lat:float,lon:float,radius_km:float,country:?string,timezone_offset:?int} $home
     *
     * @return list<Media>
     */
    private function buildPrioritizedDayQueue(array $summary, array $home): array
    {
        $timezone = $this->resolveSummaryTimezone($summary, $home);

        /** @var array<int, list<array{media:Media,score:float,timestamp:int,slotTimestamp:int}>> $slotBuckets */
        $slotBuckets = [];
        /** @var list<array{media:Media,score:float,timestamp:int,slotTimestamp:int}> $fallback */
        $fallback = [];

        foreach ($summary['members'] as $media) {
            $takenAt = $media->getTakenAt();
            $score   = $this->evaluateMediaScore($media);

            if ($takenAt instanceof DateTimeImmutable) {
                $localTime  = $takenAt->setTimezone($timezone);
                $localHour  = (int) $localTime->format('H');
                $slotIndex  = intdiv($localHour, self::DAY_SLOT_HOURS);
                $slotHour   = $slotIndex * self::DAY_SLOT_HOURS;
                $slotStart  = $localTime->setTime($slotHour, 0, 0);
                $bucketItem = [
                    'media'         => $media,
                    'score'         => $score,
                    'timestamp'     => $takenAt->getTimestamp(),
                    'slotTimestamp' => $slotStart->getTimestamp(),
                ];

                if (!isset($slotBuckets[$slotIndex])) {
                    $slotBuckets[$slotIndex] = [];
                }

                $slotBuckets[$slotIndex][] = $bucketItem;
                continue;
            }

            $fallback[] = [
                'media'         => $media,
                'score'         => $score,
                'timestamp'     => 0,
                'slotTimestamp' => 0,
            ];
        }

        /** @var list<array{media:Media,score:float,timestamp:int,slotTimestamp:int}> $winners */
        $winners = [];
        foreach ($slotBuckets as $entries) {
            usort($entries, static function (array $a, array $b): int {
                if ($a['score'] === $b['score']) {
                    if ($a['timestamp'] === $b['timestamp']) {
                        return $a['media']->getId() <=> $b['media']->getId();
                    }

                    return $a['timestamp'] <=> $b['timestamp'];
                }

                return $a['score'] < $b['score'] ? 1 : -1;
            });

            $winner = $entries[0] ?? null;
            if ($winner !== null) {
                $winners[] = $winner;
            }

            $entryCount = count($entries);
            for ($i = 1; $i < $entryCount; ++$i) {
                $fallback[] = $entries[$i];
            }
        }

        usort($winners, static function (array $a, array $b): int {
            if ($a['slotTimestamp'] === $b['slotTimestamp']) {
                return $a['timestamp'] <=> $b['timestamp'];
            }

            return $a['slotTimestamp'] <=> $b['slotTimestamp'];
        });

        usort($fallback, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return $a['timestamp'] <=> $b['timestamp'];
            }

            return $a['score'] < $b['score'] ? 1 : -1;
        });

        $ordered = [];
        foreach ($winners as $winner) {
            $ordered[] = $winner['media'];
        }

        foreach ($fallback as $entry) {
            $ordered[] = $entry['media'];
        }

        return $ordered;
    }

    private function evaluateMediaScore(Media $media): float
    {
        $resolution = $this->resolveResolutionScore($media);
        $sharpness  = $media->getSharpness();
        $isoValue   = $media->getIso();
        $isoScore   = $isoValue !== null && $isoValue > 0 ? $this->normalizeIso($isoValue) : null;

        $quality = $this->combineScores([
            [$resolution, 0.45],
            [$sharpness !== null ? $this->clamp01($sharpness) : null, 0.35],
            [$isoScore, 0.20],
        ], 0.0);

        $brightness = $media->getBrightness();
        $contrast   = $media->getContrast();
        $entropy    = $media->getEntropy();
        $color      = $media->getColorfulness();

        $aesthetics = $this->combineScores([
            [$brightness !== null ? $this->balancedScore($this->clamp01($brightness), 0.55, 0.35) : null, 0.30],
            [$contrast !== null ? $this->clamp01($contrast) : null, 0.20],
            [$entropy !== null ? $this->clamp01($entropy) : null, 0.25],
            [$color !== null ? $this->clamp01($color) : null, 0.25],
        ], 0.0);

        return (0.7 * $quality) + (0.3 * $aesthetics);
    }

    private function resolveResolutionScore(Media $media): ?float
    {
        $width  = $media->getWidth();
        $height = $media->getHeight();
        if ($width === null || $height === null || $width <= 0 || $height <= 0) {
            return null;
        }

        $megapixels = ((float) $width * (float) $height) / 1_000_000.0;

        return $this->clamp01($megapixels / max(0.000001, self::QUALITY_BASELINE_MEGAPIXELS));
    }

    private function clamp01(?float $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    /**
     * @param array<array{0: float|null, 1: float}> $components
     */
    private function combineScores(array $components, float $default): float
    {
        $sum       = 0.0;
        $weightSum = 0.0;

        foreach ($components as [$value, $weight]) {
            if ($value === null) {
                continue;
            }

            $sum += $this->clamp01($value) * $weight;
            $weightSum += $weight;
        }

        if ($weightSum <= 0.0) {
            return $default;
        }

        return $sum / $weightSum;
    }

    private function balancedScore(float $value, float $target, float $tolerance): float
    {
        $delta = abs($value - $target);
        if ($delta >= $tolerance) {
            return 0.0;
        }

        return $this->clamp01(1.0 - ($delta / $tolerance));
    }

    private function normalizeIso(int $iso): float
    {
        $min   = 50.0;
        $max   = 6400.0;
        $value = (float) max($min, min($max, $iso));
        $ratio = log($value / $min) / log($max / $min);

        return $this->clamp01(1.0 - $ratio);
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
}
